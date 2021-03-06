<?php
/* Copyright (C) 2003-2006 Rodolphe Quiedeville <rodolphe@quiedeville.org>
 * Copyright (C) 2005-2013 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2011      Jean Heimburger      <jean@tiaris.info>
 * Copyright (C) 2014	   Cedric GROSS	        <c.gross@kreiz-it.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

/**
 *	\file       htdocs/product/stock/class/mouvementstock.class.php
 *	\ingroup    stock
 *	\brief      Fichier de la classe de gestion des mouvements de stocks
 */


/**
 *	Class to manage stock movements
 */
class MouvementStock
{
    var $error;
    var $db;

    /**
	 *  Constructor
	 *
	 *  @param      DoliDB		$db      Database handler
     */
	function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 *	Add a movement of stock (in one direction only)
	 *
	 *	@param		User	$user			User object
	 *	@param		int		$fk_product		Id of product
	 *	@param		int		$entrepot_id	Id of warehouse
	 *	@param		int		$qty			Qty of movement (can be <0 or >0)
	 *	@param		int		$type			Direction of movement:
	 *										0=input (stock increase after stock transfert), 1=output (stock decrease after stock transfer),
	 *										2=output (stock decrease), 3=input (stock increase)
	 *	@param		int		$price			Unit price HT of product, used to calculate average weighted price (PMP in french). If 0, average weighted price is not changed.
	 *	@param		string	$label			Label of stock movement
	 *	@param		string	$datem			Force date of movement
	 *	@param		date	$eatby			eat-by date
	 *	@param		date	$sellby			sell-by date
	 *	@param		string	$batch			batch number
	 *	@param		boolean	$skip_sellby	If set to true, stock mouvement is done without impacting batch record
	 *	@return		int						<0 if KO, 0 if fk_product is null, >0 if OK
	 */
	function _create($user, $fk_product, $entrepot_id, $qty, $type, $price=0, $label='', $datem='',$eatby='',$sellby='',$batch='',$skip_sellby=false)
	{
		global $conf, $langs;

		require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
		$error = 0;
		dol_syslog(get_class($this)."::_create start userid=$user->id, fk_product=$fk_product, warehouse=$entrepot_id, qty=$qty, type=$type, price=$price label=$label");

		// Clean parameters
		if (empty($price)) $price=0;
		
		if (empty($fk_product)) return 0;

		$now=(! empty($datem) ? $datem : dol_now());

		$this->db->begin();

		$product = new Product($this->db);
		$result=$product->fetch($fk_product);
		if ($result < 0)
		{
			dol_print_error('',"Failed to fetch product");
			return -1;
		}
		$product->load_stock();
		
		// Define if we must make the stock change (If product type is a service or if stock is used also for services)
		$movestock=0;
		if ($product->type != 1 || ! empty($conf->global->STOCK_SUPPORTS_SERVICES)) $movestock=1;

		if ($movestock && $entrepot_id > 0)	// Change stock for current product, change for subproduct is done after
		{
			if(!empty($this->origin)) {
				$origintype = $this->origin->element;
				$fk_origin = $this->origin->id;
			} else {
				$origintype = '';
				$fk_origin = 0;
			}
			
			$sql = "INSERT INTO ".MAIN_DB_PREFIX."stock_mouvement";
			$sql.= " (datem, fk_product, fk_entrepot, value, type_mouvement, fk_user_author, label, price, fk_origin, origintype)";
			$sql.= " VALUES ('".$this->db->idate($now)."', ".$fk_product.", ".$entrepot_id.", ".$qty.", ".$type.",";
			$sql.= " ".$user->id.",";
			$sql.= " '".$this->db->escape($label)."',";
			$sql.= " '".price2num($price)."',";
			$sql.= " '".$fk_origin."',";
			$sql.= " '".$origintype."'";
			$sql.= ")";

			dol_syslog(get_class($this)."::_create sql=".$sql, LOG_DEBUG);
			$resql = $this->db->query($sql);
			if ($resql)
			{
				$mvid = $this->db->last_insert_id(MAIN_DB_PREFIX."stock_mouvement");
			}
			else
			{
				$this->error=$this->db->lasterror();
				dol_syslog(get_class($this)."::_create ".$this->error, LOG_ERR);
				$error = -1;
			}

			// Define current values for qty and pmp
			$oldqty=$product->stock_reel;
			$oldqtywarehouse=0;
			$oldpmp=$product->pmp;
			$oldpmpwarehouse=0;

			// Test if there is already a record for couple (warehouse / product)
			$num = 0;
			if (! $error)
			{
				$sql = "SELECT rowid, reel, pmp FROM ".MAIN_DB_PREFIX."product_stock";
				$sql.= " WHERE fk_entrepot = ".$entrepot_id." AND fk_product = ".$fk_product;

				dol_syslog(get_class($this)."::_create sql=".$sql);
				$resql=$this->db->query($sql);
				if ($resql)
				{
					$obj = $this->db->fetch_object($resql);
					if ($obj)
					{
						$num = 1;
						$oldqtywarehouse = $obj->reel;
						$oldpmpwarehouse = $obj->pmp;
						$fk_product_stock = $obj->rowid;
					}
					$this->db->free($resql);
				}
				else
				{
					$this->error=$this->db->lasterror();
					dol_syslog(get_class($this)."::_create echec update ".$this->error, LOG_ERR);
					$error = -2;
				}
			}

			// Calculate new PMP.
			if (! $error)
			{
				$newpmp=0;
				$newpmpwarehouse=0;
				// Note: PMP is calculated on stock input only (type = 0 or 3). If type == 0 or 3, qty should be > 0.
				// Note: Price should always be >0 or 0. PMP should be always >0 (calculated on input)
				if (($type == 0 || $type == 3) && $price > 0)
				{
					$oldqtytouse=($oldqty >= 0?$oldqty:0);
					// We make a test on oldpmp>0 to avoid to use normal rule on old data with no pmp field defined
					if ($oldpmp > 0) $newpmp=price2num((($oldqtytouse * $oldpmp) + ($qty * $price)) / ($oldqtytouse + $qty), 'MU');
					else $newpmp=$price;
					$oldqtywarehousetouse=($oldqtywarehouse >= 0?$oldqtywarehouse:0);
					if ($oldpmpwarehouse > 0) $newpmpwarehouse=price2num((($oldqtywarehousetouse * $oldpmpwarehouse) + ($qty * $price)) / ($oldqtywarehousetouse + $qty), 'MU');
					else $newpmpwarehouse=$price;

					//print "oldqtytouse=".$oldqtytouse." oldpmp=".$oldpmp." oldqtywarehousetouse=".$oldqtywarehousetouse." oldpmpwarehouse=".$oldpmpwarehouse." ";
					//print "qty=".$qty." newpmp=".$newpmp." newpmpwarehouse=".$newpmpwarehouse;
					//exit;
				}
				else
				{
					$newpmp = $oldpmp;
					$newpmpwarehouse = $oldpmpwarehouse;
				}
			}

			// Update denormalized value of stock in product_stock and product
			if (! $error)
			{
				if ($num > 0)
				{
					$sql = "UPDATE ".MAIN_DB_PREFIX."product_stock SET pmp = ".$newpmpwarehouse.", reel = reel + ".$qty;
					$sql.= " WHERE fk_entrepot = ".$entrepot_id." AND fk_product = ".$fk_product;
				}
				else
				{
					$sql = "INSERT INTO ".MAIN_DB_PREFIX."product_stock";
					$sql.= " (pmp, reel, fk_entrepot, fk_product) VALUES ";
					$sql.= " (".$newpmpwarehouse.", ".$qty.", ".$entrepot_id.", ".$fk_product.")";
				}

				dol_syslog(get_class($this)."::_create sql=".$sql);
				$resql=$this->db->query($sql);
				if (! $resql)
				{
					$this->error=$this->db->lasterror();
					dol_syslog(get_class($this)."::_create ".$this->error, LOG_ERR);
					$error = -3;
				} else if(empty($fk_product_stock)){
					$fk_product_stock = $this->db->last_insert_id(MAIN_DB_PREFIX."product_stock");
				}

				}

			// Update detail stock for sell-by date
			if (($product->hasbatch()) && (! $error) && (! $skip_sellby)){
				$param_batch=array('fk_product_stock' =>$fk_product_stock, 'eatby'=>$eatby,'sellby'=>$sellby,'batchnumber'=>$batch);
				$result=$this->_create_batch($param_batch, $qty);
				if ($result<0) $error++;
			}

			if (! $error)
			{
				$sql = "UPDATE ".MAIN_DB_PREFIX."product SET pmp = ".$newpmp.", stock = ".$this->db->ifsql("stock IS NULL", 0, "stock") . " + ".$qty;
				$sql.= " WHERE rowid = ".$fk_product;
				// May be this request is better:
				// UPDATE llx_product p SET p.stock= (SELECT SUM(ps.reel) FROM llx_product_stock ps WHERE ps.fk_product = p.rowid);

				dol_syslog(get_class($this)."::_create sql=".$sql);
				$resql=$this->db->query($sql);
				if (! $resql)
				{
					$this->error=$this->db->lasterror();
					dol_syslog(get_class($this)."::_create ".$this->error, LOG_ERR);
					$error = -4;
				}
			}
		}

		// Add movement for sub products (recursive call)
		if (! $error && ! empty($conf->global->PRODUIT_SOUSPRODUITS))
		{
			$error = $this->_createSubProduct($user, $fk_product, $entrepot_id, $qty, $type, 0, $label);	// we use 0 as price, because pmp is not changed for subproduct
		}

		if ($movestock && ! $error)
		{
			// Appel des triggers
			include_once DOL_DOCUMENT_ROOT . '/core/class/interfaces.class.php';
			$interface=new Interfaces($this->db);

			$this->product_id = $fk_product;
			$this->entrepot_id = $entrepot_id;
			$this->qty = $qty;

			$result=$interface->run_triggers('STOCK_MOVEMENT',$this,$user,$langs,$conf);
			if ($result < 0) { $error++; $this->errors=$interface->errors; }
			// Fin appel triggers
		}

		if (! $error)
		{
			$this->db->commit();
			return 1;
		}
		else
		{
			$this->db->rollback();
			dol_syslog(get_class($this)."::_create error code=".$error, LOG_ERR);
			return -6;
		}
	}


	/**
	 *  Create movement in database for all subproducts
	 *
	 * 	@param 		User	$user			Object user
	 * 	@param		int		$idProduct		Id product
	 * 	@param		int		$entrepot_id	Warehouse id
	 * 	@param		int		$qty			Quantity
	 * 	@param		int		$type			Type
	 * 	@param		int		$price			Price
	 * 	@param		string	$label			Label of movement
	 * 	@return 	int     				<0 if KO, 0 if OK
	 */
	function _createSubProduct($user, $idProduct, $entrepot_id, $qty, $type, $price=0, $label='')
	{
		$error = 0;
		$pids = array();
		$pqtys = array();

		$sql = "SELECT fk_product_pere, fk_product_fils, qty";
		$sql.= " FROM ".MAIN_DB_PREFIX."product_association";
		$sql.= " WHERE fk_product_pere = ".$idProduct;

		dol_syslog(get_class($this)."::_createSubProduct sql=".$sql, LOG_DEBUG);
		$resql=$this->db->query($sql);
		if ($resql)
		{
			$i=0;
			while ($obj=$this->db->fetch_object($resql))
			{
				$pids[$i]=$obj->fk_product_fils;
				$pqtys[$i]=$obj->qty;
				$i++;
			}
			$this->db->free($resql);
		}
		else
		{
			dol_syslog(get_class($this)."::_createSubProduct ".$this->error, LOG_ERR);
			$error = -2;
		}

		// Create movement for each subproduct
		foreach($pids as $key => $value)
		{
			$this->_create($user, $pids[$key], $entrepot_id, ($qty * $pqtys[$key]), $type, 0, $label);
		}

		return $error;
	}


	/**
	 *	Decrease stock for product and subproducts
	 *
	 * 	@param 		User	$user			Object user
	 * 	@param		int		$fk_product		Id product
	 * 	@param		int		$entrepot_id	Warehouse id
	 * 	@param		int		$qty			Quantity
	 * 	@param		int		$price			Price
	 * 	@param		string	$label			Label of stock movement
	 * 	@param		string	$datem			Force date of movement
	 * 	@return		int						<0 if KO, >0 if OK
	 */
	function livraison($user, $fk_product, $entrepot_id, $qty, $price=0, $label='', $datem='')
	{
		return $this->_create($user, $fk_product, $entrepot_id, (0 - $qty), 2, $price, $label, $datem,'','','',true);
	}

	/**
	 *	Decrease stock for batch record
	 *
	 * 	@param		int		$id_stock_dluo		Id product_dluo
	 * 	@param		int		$qty			Quantity
	 * 	@return		int						<0 if KO, >0 if OK
	 */
	function livraison_batch($id_stock_dluo, $qty)
	{
		$ret=$this->_create_batch($id_stock_dluo, (0 - $qty));
		return $ret;
	}

	/**
	 *	Increase stock for product and subproducts
	 *
	 * 	@param 		User	$user			Object user
	 * 	@param		int		$fk_product		Id product
	 * 	@param		int		$entrepot_id	Warehouse id
	 * 	@param		int		$qty			Quantity
	 * 	@param		int		$price			Price
	 * 	@param		string	$label			Label of stock movement
	 *	@param		date	$eatby			eat-by date
	 *	@param		date	$sellby			sell-by date
	 *	@param		string	$batch			batch number
	 *	@return		int						<0 if KO, >0 if OK
	 */
	function reception($user, $fk_product, $entrepot_id, $qty, $price=0, $label='', $eatby='', $sellby='', $batch='')
	{
		return $this->_create($user, $fk_product, $entrepot_id, $qty, 3, $price, $label, '', $eatby, $sellby, $batch);
	}


	/**
	 * Return nb of subproducts lines for a product
	 *
	 * @param      int		$id				Id of product
	 * @return     int						<0 if KO, nb of subproducts if OK
	 */
	function nbOfSubProdcuts($id)
	{
		$nbSP=0;

		$resql = "SELECT count(*) as nb FROM ".MAIN_DB_PREFIX."product_association";
		$resql.= " WHERE fk_product_pere = ".$id;
		if ($this->db->query($resql))
		{
			$obj=$this->db->fetch_object($resql);
			$nbSP=$obj->nb;
		}
		return $nbSP;
	}

	/**
	 * Count number of product in stock before a specific date
	 *  
	 * @param 	int			$productidselected		Id of product to count
	 * @param 	timestamp	$datebefore				Date limit
	 * @return	int			Number
	 */
	function calculateBalanceForProductBefore($productidselected, $datebefore)
	{
		$nb=0;
		
		$sql = 'SELECT SUM(value) as nb from '.MAIN_DB_PREFIX.'stock_mouvement';
		$sql.= ' WHERE fk_product = '.$productidselected;
		$sql.= " AND datem < '".$this->db->idate($datebefore)."'";
		
		dol_syslog(get_class($this).__METHOD__.' sql='.$sql);
		$resql=$this->db->query($sql);
		if ($resql)
		{
			$obj=$this->db->fetch_object($resql);
			if ($obj) $nb = $obj->nb;
			return (empty($nb)?0:$nb);
		}
		else 
		{
			dol_print_error($this->db);
			return -1;
		}
	}

	/**
	 * Create or update batch record
	 *
	 * @param	variant		$dluo	Could be either int if id of product_batch or array with at leat fk_product_stock
	 * @param	int			$qty	Quantity of product with batch number
	 * @return 	int   				<0 if KO, else return productbatch id
	 */
	function _create_batch($dluo, $qty ) {
		$pdluo=New Productbatch($this->db);
		
		//Try to find an existing record with batch same batch number or id
		if (is_numeric($dluo)) {
			$result=$pdluo->fetch($dluo);
		} else if (is_array($dluo)) {
			if (isset($dluo['fk_product_stock'])) {
				$vfk_product_stock=$dluo['fk_product_stock'];
				$veatby = $dluo['eatby'];
				$vsellby = $dluo['sellby'];
				$vbatchnumber = $dluo['batchnumber'];
				$result = $pdluo->find($vfk_product_stock,$veatby,$vsellby,$vbatchnumber);
			} else {
				dol_syslog(get_class($this)."::_create_batch array param dluo must contain at least key fk_product_stock".$error, LOG_ERR);
				$result = -1;
			}
		} else {
			dol_syslog(get_class($this)."::_create_batch error invalid param dluo".$error, LOG_ERR);
			$result =  -1;
		}

		//batch record found so we update it
		if ($result>0) {
			if ($pdluo->id >0) {
				$pdluo->qty +=$qty;
				if ($pdluo->qty == 0) {
					$result=$pdluo->delete(0,1);
				} else {
					$result=$pdluo->update(0,1);
				}
			} else {
				$pdluo->fk_product_stock=$vfk_product_stock;
				$pdluo->qty = $qty;
				$pdluo->eatby = $veatby;
				$pdluo->sellby = $vsellby;
				$pdluo->batch = $vbatchnumber;
				$result=$pdluo->create(0,1);
			}
			return $result;
		} else {
			return -1;
		}

	}
	
	function get_origin($fk_origin, $origintype) {
		switch ($origintype) {
			case 'commande':
				require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
				$origin = new Commande($this->db);
				break;
			case 'shipping':
				require_once DOL_DOCUMENT_ROOT.'/expedition/class/expedition.class.php';
				$origin = new Expedition($this->db);
				break;
			case 'facture':
				require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
				$origin = new Facture($this->db);
				break;
			case 'order_supplier':
				require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
				$origin = new CommandeFournisseur($this->db);
				break;
			case 'invoice_supplier':
				require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
				$origin = new FactureFournisseur($this->db);
				break;
			
			default:
				return '';
				break;
		}
		
		$origin->fetch($fk_origin);
		return $origin->getNomUrl(1);
	}
}
