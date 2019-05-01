<?php
// $HeadURL: https://joomgallery.org/svn/joomgallery/JG-2.0/Plugins/XMap/trunk/com_joomgallery.php $
// $Id: com_joomgallery.php 4163 2013-03-31 14:57:41Z chraneco $
/****************************************************************************************\
**   JoomGallery Plugin for XMap                                                        **
**   By: JoomGallery::ProjectTeam                                                       **
**   Copyright (C) 2013 - 2013  JoomGallery::ProjectTeam                                **
**   Released under GNU GPL Public License                                              **
**   License: http://www.gnu.org/copyleft/gpl.html or have a look                       **
**   at administrator/components/com_joomgallery/LICENSE.TXT                            **
\****************************************************************************************/

defined('_JEXEC') or die('Direct Access to this location is not allowed.');

/**
 * Handles standard JoomGallery images and categories
 *
 * @package JoomGallery
 * @since   1.0
 */
class xmap_com_joomgallery
{
  /**
   * Holds the interface object of JoomGallery
   *
   * @var   JoomInterface
   * @since 2.0
   */
  static $jginterface;

  /**
   * This function is called before a menu item is printed. We use it to set the
   * proper unique ID for the item
   *
   * @param   object  $node   Menu item to be "prepared"
   * @param   array   $params The extension parameters
   * @return  void
   * @since   1.0
   */
  static function prepareMenuItem(&$node,&$params)
  {
    $link_query = parse_url($node->link);
    parse_str(html_entity_decode($link_query['query']), $link_vars);
    $view = JArrayHelper::getValue($link_vars, 'view', '', '');

    if($view =='detail')
    {
      $id = intval(JArrayHelper::getValue($link_vars, 'id', 0));
      $node->uid = 'com_joomgalleryi'.$id;
      $node->expandible = false;
    }
    else
    {
      if($view =='category')
      {
        $cid = intval(JArrayHelper::getValue($link_vars, 'catid', 0));
        $node->uid = 'com_joomgalleryc'.$cid;
        $node->expandible = true;
      }
    }
  }

  /**
   * Gets the tree of category structure
   *
   * @param   object  $xmap   The XMap displayer object
   * @param   object  $parent The parent node
   * @param   array   $params The extension parameters
   * @return  void
   * @since   1.0
   */
  static function getTree($xmap, $parent, &$params)
  {
    if($xmap->isNews) 
    {
      // This component does not provide news content.
      // Don't waste time and resources.
      return;
    }

    $link_query = parse_url($parent->link);
    if(!isset($link_query['query']))
    {
      return;
    }

    // Get the interface of JoomGallery
    self::getJGInterface();

    parse_str(html_entity_decode($link_query['query']), $link_vars);

    // Get the parameters
    // Set expand_categories param to determine the search of image items
    $expand_categories = JArrayHelper::getValue($params, 'expand_categories', 1);
    $expand_categories = ((     $expand_categories == 1
                            ||  ($expand_categories == 2 && $xmap->view == 'xml')
                            ||    ($expand_categories == 3 && $xmap->view == 'html')
                            || $xmap->view == 'navigator'
                          )
                          && self::$jginterface->getJConfig('jg_detailpic_open') == 0
                          && self::$jginterface->getJConfig('jg_showdetailpage') == 1);
    $params['expand_categories'] = $expand_categories;

    // Set cat_priority and cat_changefreq params
    $priority = JArrayHelper::getValue($params, 'cat_priority', $parent->priority);
    $changefreq = JArrayHelper::getValue($params, 'cat_changefreq', $parent->changefreq);
    if($priority == '-1')
    {
      $priority = $parent->priority;
    }
    if($changefreq == '-1')
    {
      $changefreq = $parent->changefreq;
    }

    $params['cat_priority'] = $priority;
    $params['cat_changefreq'] = $changefreq;

    // Set img_priority and img_changefreq params
    $priority = JArrayHelper::getValue($params, 'img_priority', $parent->priority);
    $changefreq = JArrayHelper::getValue($params, 'img_changefreq', $parent->changefreq);
    if($priority == '-1')
    {
      $priority = $parent->priority;
    }
    if($changefreq == '-1')
    {
      $changefreq = $parent->changefreq;
    }

    $params['img_priority'] = $priority;
    $params['img_changefreq'] = $changefreq;

    $params['max_images'] = intval(JArrayHelper::getValue($params, 'max_images', 0));

    $cid = intval(JArrayHelper::getValue($link_vars, 'catid', 1));

    self::expandCategory($xmap, $parent, $cid, $params, $parent->id);
  }

  /**
   * Add category items and images within a category
   *
   * @param   object  $xmap   The XMap displayer object
   * @param   object  $parent The parent node
   * @param   int     $catid  The ID of the category to expand
   * @param   array   $params The extension parameters
   * @param   int     $itemid The itemid to use for this category's children
   * @return  void
   * @since   1.0
   */
  static function expandCategory($xmap, $parent, $catid, &$params, $itemid)
  {
    if(!self::$jginterface)
    {
      return;
    }

    // Get category structure
    static $catstructure = null;
    if(!$catstructure)
    {
      $catstructure = self::$jginterface->getAmbit()->getCategoryStructure();
    }

    if(empty($catstructure))
    {
      // No viewable category in gallery
      return;
    }

    // If catid = 1 call getRootCats() to get the cats at most upper level
    if($catid == 1)
    {
      $rootcats = self::getRootCats();
      $subcats = $rootcats;
    }
    else
    {
      // Include images of category if configured.
      // Deny if detail view of JoomGallery is not reachable.
      if($params['expand_categories'])
      {
        self::getImages($xmap, $parent, $catid, $params, $itemid);
      }

      // Get sub-categories of category
      // Returns an array with catids, so construct an array with objects needed for the nodes
      $subcatsjg = JoomHelper::getAllSubCategories($catid, false, true);
      $subcats = array();
      foreach($subcatsjg as $key => $value)
      {
        // Deny elements with wrong hierarchy level, because getAllSubCategories delivers subcategories of all levels
        if($catstructure[$value]->parent_id == $catid)
        {
          if(!isset($subcats[$key]))
          {
            $subcats[$key] = new stdClass();
          }

          $subcats[$key]->cid = $value;
        }
      }
    }

    if(count($subcats) > 0)
    {
      $xmap->changeLevel(1);
      foreach($subcats as $subcat)
      {
        $node             = new stdClass();
        $node->id         = $parent->id;
        $node->uid        = $parent->uid.'c'.$subcat->cid;
        $node->browserNav = $parent->browserNav;
        $node->priority   = $params['cat_priority'];
        $node->changefreq = $params['cat_changefreq'];

        $modifieddate     =  self::getModifiedDate($subcat->cid);
        if(!is_null($modifieddate))
        {
          $node->modified = $modifieddate;
        }
        $node->name       = $catstructure[$subcat->cid]->name;
        $node->expandible = true;
        $node->secure     = $parent->secure;
        $node->keywords   = $catstructure[$subcat->cid]->name;
        $node->newsItem   = 0;
        $node->slug       = $subcat->cid;
        $node->link       = 'index.php?option=com_joomgallery&amp;view=category&amp;catid='.$subcat->cid.'&Itemid='.$parent->id;
        $node->itemid     = $parent->id;

        // Print the category node and look recursively for sub-categories
        if($xmap->printNode($node))
        {
          self::expandCategory($xmap, $parent, $subcat->cid, $params, $node->itemid);
        }
      }

      $xmap->changeLevel(-1);
    }
  }

  /**
   * Add all image items within a category
   *
   * @param   object  $xmap   The XMap displayer object
   * @param   object  $parent The parent node
   * @param   int     $catid  The ID of the category to expand
   * @param   array   $params The extension parameters
   * @param   int     $itemid The itemid to use for this category's children
   * @return  void
   * @since   1.0
   */
  static function getImages($xmap, $parent, $catid, &$params, $Itemid)
  {
    // Get images from interface, ordered by imgdate
    $images = self::$jginterface->getPicsByCategory($catid, null, 'imgdate desc', $params['max_images']);

    if(count($images) > 0)
    {
      $xmap->changeLevel(1);
      foreach($images as $image)
      {
        $node             = new stdClass();
        $node->id         = $parent->id;
        $node->uid        = $parent->uid . 'i' . $image->id;
        $node->browserNav = $parent->browserNav;
        $node->priority   = $params['img_priority'];
        $node->changefreq = $params['img_changefreq'];
        $node->name       = $image->imgtitle;

        // Convert imgdate to timestamp
        $node->modified   = strtotime($image->imgdate);
        $node->expandible = false;
        $node->secure     = $parent->secure;
        $node->keywords   = $image->imgtitle;
        $node->newsItem   = 0;
        $node->language   = null;
        $node->link       = 'index.php?option=com_joomgallery&amp;view=detail&amp;id='.$image->id.'&Itemid='.$parent->id;
        $xmap->printNode($node);
      }

      $xmap->changeLevel(-1);
    }
  }

  /**
   * Loads the interface object of JoomGallery
   *
   * @return  void
   * @since   2.0
   */
  private static function getJGInterface()
  {
    if(self::$jginterface)
    {
      return;
    }

    // Check if JoomGallery component is installed and enabled
    if(!JComponentHelper::isEnabled('com_joomgallery', true))
    {
      return;
    }

    // Check if file of interface exists
    $jg_interface = JPATH_SITE.'/components/com_joomgallery/interface.php';
    if(!is_file($jg_interface))
    {
      return;
    }

    require_once $jg_interface;

    self::$jginterface = new JoomInterface();
  }

  /**
   * Get the root categories of gallery
   *
   * @return  array An array of category items
   * @since   1.0
   */
  private static function getRootCats()
  {
    $user = JFactory::getUser();
    $db   = JFactory::getDbo();

    $query = $db->getQuery(true)
          ->select('c.cid')
          ->from(_JOOM_TABLE_CATEGORIES.' AS c')
          ->where('c.published = 1')
          ->where('c.hidden    = 0')
          ->where('c.parent_id = 1')
          ->where('c.access IN ('.implode(',', $user->getAuthorisedViewLevels()).')')
          ->order('c.lft');
    $db->setQuery($query);

    return $db->loadObjectList();
  }

  /**
   * Get the latest image date to determine the modification date of category
   *
   * @param   int     $cid  ID of the category
   * @return  string  The latest image date of the category
   * @since   1.0
   */
  private static function getModifiedDate(&$cid)
  {
    $image = self::$jginterface->getPicsByCategory($cid, null, 'imgdate desc', 1, 0);

    if(empty($image))
    {
      $imgdate = null;
    }
    else
    {
      $imgdate = strtotime($image[0]->imgdate);
    }

    return $imgdate;
  }
}