<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2014 Leo Feyer
 *
 * @package News
 * @link    https://contao.org
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL
 */


/**
 * Run in a custom namespace, so the class can be replaced
 */
namespace catalog;


/**
 * Class ModuleCatalog
 *
 * Parent class for catalog modules.
 * @copyright  Hamid Abbaszadeh 2014
 * @author     Hamid Abbaszadeh <https://respinar.com>
 * @package    Kitchenware
 */
abstract class ModuleCatalog extends \Module
{

	/**
	 * URL cache array
	 * @var array
	 */
	private static $arrUrlCache = array();


	/**
	 * Sort out protected archives
	 * @param array
	 * @return array
	 */
	protected function sortOutProtected($arrCategories)
	{
		if (BE_USER_LOGGED_IN || !is_array($arrCategories) || empty($arrCategories))
		{
			return $arrCategories;
		}

		$this->import('FrontendUser', 'User');
		$objCategory = \CatalogCategoryModel::findMultipleByIds($arrCategories);
		$arrCategories = array();

		if ($objCategory !== null)
		{
			while ($objCategory->next())
			{
				if ($objCategory->protected)
				{
					if (!FE_USER_LOGGED_IN)
					{
						continue;
					}

					$groups = deserialize($objCategory->groups);

					if (!is_array($groups) || empty($groups) || !count(array_intersect($groups, $this->User->groups)))
					{
						continue;
					}
				}

				$arrCategories[] = $objCategory->id;
			}
		}

		return $arrCategories;
	}


	/**
	 * Parse an item and return it as string
	 * @param object
	 * @param boolean
	 * @param string
	 * @param integer
	 * @return string
	 */
	protected function parseProduct($objProduct, $blnAddCategory=false, $strClass='', $intCount=0)
	{
		global $objPage;

		$objTemplate = new \FrontendTemplate($this->product_template);
		$objTemplate->setData($objProduct->row());

		$objTemplate->featured_text = "Featured";
		$objTemplate->new_text = "New";

		$objTemplate->class = (($this->product_Class != '') ? ' ' . $this->product_Class : '') . $strClass;
		$objTemplate->type_Class = $this->type_Class;

		if (time() - $objProduct->date < 2592000) {
			$objTemplate->new_product = true;
		}

		$objTemplate->link        = $this->generateProductUrl($objProduct, $blnAddCategory);

		$arrMeta = $this->getMetaFields($objProduct);

		$objTemplate->types       = $this->parseType($objProduct);

		$objTemplate->category    = $objProduct->getRelated('pid');

		$objTemplate->count = $intCount; // see #5708

		$arrMeta = $this->getMetaFields($objProduct);

		// Add the meta information
		$objTemplate->date = $arrMeta['date'];
		$objTemplate->hasMetaFields = !empty($arrMeta);
		$objTemplate->timestamp = $objProduct->date;
		$objTemplate->datetime = date('Y-m-d\TH:i:sP', $objProduct->date);

		$objTemplate->addImage = false;

		// Add an image
		if ($objProduct->singleSRC != '')
		{
			$objModel = \FilesModel::findByUuid($objProduct->singleSRC);

			if ($objModel === null)
			{
				if (!\Validator::isUuid($objProduct->singleSRC))
				{
					$objTemplate->text = '<p class="error">'.$GLOBALS['TL_LANG']['ERR']['version2format'].'</p>';
				}
			}
			elseif (is_file(TL_ROOT . '/' . $objModel->path))
			{
				// Do not override the field now that we have a model registry (see #6303)
				$arrProduct = $objProduct->row();

				// Override the default image size
				if ($this->imgSize != '')
				{
					$size = deserialize($this->imgSize);

					if ($size[0] > 0 || $size[1] > 0 || is_numeric($size[2]))
					{
						$arrProduct['size'] = $this->imgSize;
					}
				}

				$arrProduct['singleSRC'] = $objModel->path;
				$strLightboxId = 'lightbox[lb' . $objProduct->id . ']';
				$arrProduct['fullsize'] = $this->fullsize;
				$this->addImageToTemplate($objTemplate, $arrProduct,null, $strLightboxId);
			}
		}

		$objElement = \ContentModel::findPublishedByPidAndTable($objProduct->id, 'tl_catalog_product');

		if ($objElement !== null)
		{
			while ($objElement->next())
			{
				$objTemplate->text .= $this->getContentElement($objElement->current());
			}
		}

		$objTemplate->enclosure = array();

		// Add enclosures
		if ($objProduct->addEnclosure)
		{
			$this->addEnclosuresToTemplate($objTemplate, $objProduct->row());
		}

		$objTemplate->features_text = $GLOBALS['TL_LANG']['MSC']['features'];
		$objTemplate->specs_text = $GLOBALS['TL_LANG']['MSC']['specs'];
		$objTemplate->model_text = $GLOBALS['TL_LANG']['MSC']['model_text'];


		return $objTemplate->parse();
	}


	/**
	 * Parse one or more items and return them as array
	 * @param object
	 * @param boolean
	 * @return array
	 */
	protected function parseProducts($objProducts, $blnAddCategory=false)
	{
		$limit = $objProducts->count();

		if ($limit < 1)
		{
			return array();
		}

		$count = 0;
		$arrProducts = array();

		while ($objProducts->next())
		{
			$arrProducts[] = $this->parseProduct($objProducts, $blnAddCategory, ((++$count == 1) ? ' first' : '') . (($count == $limit) ? ' last' : '') . ((($count % $this->product_perRow) == 0) ? ' last_col' : '') . ((($count % $this->product_perRow) == 1) ? ' first_col' : ''), $count);
		}

		return $arrProducts;
	}

	/**
	 * Generate a URL and return it as string
	 * @param object
	 * @param boolean
	 * @return string
	 */
	protected function generateProductUrl($objItem, $blnAddCategory=false)
	{
		$strCacheKey = 'id_' . $objItem->id;

		// Load the URL from cache
		if (isset(self::$arrUrlCache[$strCacheKey]))
		{
			return self::$arrUrlCache[$strCacheKey];
		}

		// Initialize the cache
		self::$arrUrlCache[$strCacheKey] = null;

		// Link to the default page
		if (self::$arrUrlCache[$strCacheKey] === null)
		{
			$objPage = \PageModel::findByPk($objItem->getRelated('pid')->jumpTo);

			if ($objPage === null)
			{
				self::$arrUrlCache[$strCacheKey] = ampersand(\Environment::get('request'), true);
			}
			else
			{
				self::$arrUrlCache[$strCacheKey] = ampersand($this->generateFrontendUrl($objPage->row(), ((\Config::get('useAutoItem') && !\Config::get('disableAlias')) ?  '/' : '/items/') . ((!\Config::get('disableAlias') && $objItem->alias != '') ? $objItem->alias : $objItem->id)));
			}

		}

		return self::$arrUrlCache[$strCacheKey];
	}


	/**
	 * Generate a link and return it as string
	 * @param string
	 * @param object
	 * @param boolean
	 * @param boolean
	 * @return string
	 */
	protected function generateLink($strLink, $objProduct, $blnAddCategory=false, $blnIsReadMore=false)
	{

		return sprintf('<a href="%s" title="%s">%s%s</a>',
						$this->generateProductUrl($objProduct, $blnAddCategory),
						specialchars(sprintf($GLOBALS['TL_LANG']['MSC']['readMore'], $objProduct->title), true),
						$strLink,
						($blnIsReadMore ? ' <span class="invisible">'.$objProduct->title.'</span>' : ''));

	}

	/**
	 * Generate a elements as array
	 * @param string
	 * @param object
	 * @param boolean
	 * @param boolean
	 * @return string
	 */
	protected function parseType($objType)
	{
		$objTemplate = new \FrontendTemplate($this->type_template);
		$objTemplate->setData($objType->row());

		$objTemplate->class = (($this->type_Class != '') ? ' ' . $this->type_Class : '') . $strClass;

		if (time() - $objType->date < 2592000) {
			$objTemplate->new_type = true;
		}

		$arrMeta = $this->getMetaFields($objType);

		$objTemplate->product    = $objType->getRelated('pid');

		$objTemplate->count = $intCount; // see #5708

		$arrMeta = $this->getMetaFields($objType);

		// Add the meta information
		$objTemplate->date = $arrMeta['date'];
		$objTemplate->hasMetaFields = !empty($arrMeta);
		$objTemplate->timestamp = $objType->date;
		$objTemplate->datetime = date('Y-m-d\TH:i:sP', $objType->date);

		$objTemplate->addImage = false;

		// Add an image
		if ($objType->singleSRC != '')
		{
			$objModel = \FilesModel::findByUuid($objType->singleSRC);

			if ($objModel === null)
			{
				if (!\Validator::isUuid($objType->singleSRC))
				{
					$objTemplate->text = '<p class="error">'.$GLOBALS['TL_LANG']['ERR']['version2format'].'</p>';
				}
			}
			elseif (is_file(TL_ROOT . '/' . $objModel->path))
			{
				// Do not override the field now that we have a model registry (see #6303)
				$arrType = $objType->row();

				// Override the default image size
				if ($this->type_imgSize != '')
				{
					$size = deserialize($this->type_imgSize);

					if ($size[0] > 0 || $size[1] > 0 || is_numeric($size[2]))
					{
						$arrType['size'] = $this->type_imgSize;
					}
				}

				$arrType['singleSRC'] = $objModel->path;
				$strLightboxId = 'lightbox[lb' . $objType->id . ']';
				$arrType['fullsize'] = true;
				$this->addImageToTemplate($objTemplate, $arrType,null, $strLightboxId);
			}
		}

		return $objTemplate->parse();

	}

	/**
	 * Return the meta fields of a news article as array
	 * @param object
	 * @return array
	 */
	protected function getMetaFields($objProduct)
	{
		$meta = deserialize($this->catalog_metaFields);

		if (!is_array($meta))
		{
			return array();
		}

		global $objPage;
		$return = array();

		foreach ($meta as $field)
		{
			switch ($field)
			{
				case 'date':
					$return['date'] = \Date::parse($objPage->datimFormat, $objProduct->date);
					break;
			}
		}

		return $return;
	}

	/**
	 * Parse an item and return it as string
	 * @param object
	 * @param boolean
	 * @param string
	 * @param integer
	 * @return string
	 */
	protected function parseRelated($objProduct, $blnAddCategory=false, $strClass='', $intCount=0)
	{
		$objTemplate = new \FrontendTemplate($this->related_template);
		$objTemplate->setData($objProduct->row());

		$objTemplate->class = (($this->related_Class != '') ? ' ' . $this->related_Class : '') . $strClass;

		if (time() - $objProduct->date < 2592000) {
			$objTemplate->new_product = true;
		}

		$objTemplate->link        = $this->generateProductUrl($objProduct, $blnAddCategory);

		$arrMeta = $this->getMetaFields($objProduct);

		$objTemplate->category    = $objProduct->getRelated('pid');

		$objTemplate->count = $intCount; // see #5708

		$arrMeta = $this->getMetaFields($objProduct);

		// Add the meta information
		$objTemplate->date = $arrMeta['date'];
		$objTemplate->hasMetaFields = !empty($arrMeta);
		$objTemplate->timestamp = $objProduct->date;
		$objTemplate->datetime = date('Y-m-d\TH:i:sP', $objProduct->date);

		$objTemplate->addImage = false;

		// Add an image
		if ($objProduct->singleSRC != '')
		{
			$objModel = \FilesModel::findByUuid($objProduct->singleSRC);

			if ($objModel === null)
			{
				if (!\Validator::isUuid($objProduct->singleSRC))
				{
					$objTemplate->text = '<p class="error">'.$GLOBALS['TL_LANG']['ERR']['version2format'].'</p>';
				}
			}
			elseif (is_file(TL_ROOT . '/' . $objModel->path))
			{
				// Do not override the field now that we have a model registry (see #6303)
				$arrProduct = $objProduct->row();

				// Override the default image size
				if ($this->related_imgSize != '')
				{
					$size = deserialize($this->related_imgSize);

					if ($size[0] > 0 || $size[1] > 0 || is_numeric($size[2]))
					{
						$arrProduct['size'] = $this->related_imgSize;
					}
				}

				$arrProduct['singleSRC'] = $objModel->path;
				$strLightboxId = 'lightbox[lb' . $objProduct->id . ']';
				$arrProduct['fullsize'] = false;
				$this->addImageToTemplate($objTemplate, $arrProduct,null, $strLightboxId);
			}
		}

		return $objTemplate->parse();
	}

	/**
	 * Parse one or more items and return them as array
	 * @param object
	 * @param boolean
	 * @return array
	 */
	protected function parseRelateds($objProducts, $blnAddCategory=false)
	{
		$limit = $objProducts->count();

		if ($limit < 1)
		{
			return array();
		}

		$count = 0;
		$arrProducts = array();

		while ($objProducts->next())
		{
			$arrProducts[] = $this->parseRelated($objProducts, $blnAddCategory, ((++$count == 1) ? ' first' : '') . (($count == $limit) ? ' last' : '') . ((($count % $this->related_perRow) == 0) ? ' last_col' : '') . ((($count % $this->related_perRow) == 1) ? ' first_col' : ''), $count);
		}

		return $arrProducts;
	}


	/**
	 * Parse one or more items and return them as array
	 * @param object
	 * @param boolean
	 * @return array
	 */
	protected function parseTypes($objTypes, $blnAddCategory=false)
	{
		$limit = $objTypes->count();

		if ($limit < 1)
		{
			return array();
		}

		$count = 0;
		$arrTypes = array();

		while ($objTypes->next())
		{
			$arrTypes[] = $this->parseType($objTypes, $blnAddCategory, ((++$count == 1) ? ' first' : '') . (($count == $limit) ? ' last' : '') . ((($count % $this->type_perRow) == 0) ? ' last_col' : '') . ((($count % $this->type_perRow) == 1) ? ' first_col' : ''), $count);
		}

		return $arrTypes;
	}

}
