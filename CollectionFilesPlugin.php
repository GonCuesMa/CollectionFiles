<?php
/**
 * Collection Files
 *
 * @license GPLv3
 */

/**
 * "Collection Files" plugin.
 *
 */
class CollectionFilesPlugin extends Omeka_Plugin_AbstractPlugin
{
    protected $_elementSetName = 'Monitor';

    private $_files;
    /**
     * @var array Hooks for the plugin.
     */
    protected $_hooks = array(
        'install',
        'uninstall',
        'uninstall_message',
        'admin_head',
        'admin_collections_form',
        'admin_collections_show',
        'admin_collections_show_sidebar',
        'admin_collections_browse',
        'admin_collections_browse_each',
        'before_save_collection',
        'after_save_collection',
        'define_routes',
        'define_acl',
    );

    /**
     * @var array Filters for the plugin.
     */
    protected $_filters = array(
        'admin_collections_form_tabs',
    );
    
    /**
     * HOOK: Defining routes.
     *
     * @param array $args
     */
    public function hookDefineRoutes($args)
    {
        $args['router']->addConfig(new Zend_Config_Ini(dirname(__FILE__) . '/routes.ini', 'routes'));
    }
    
    /**
     * Define the plugin's access control list.
     *
     * @param array $args Parameters supplied by the hook
     * @return void
     */
    public function hookDefineAcl($args)
    {
        $args['acl']->addResource('CollectionFiles');
    }
    
    /**
     * Install the plugin.
     */
    public function hookInstall()
    {
        $database = get_db();
        $sql = "
        CREATE TABLE IF NOT EXISTS `$database->CollectionFile` (
            `id` int unsigned NOT NULL auto_increment,
            `collection_id` int unsigned NOT NULL,
            `order` int(10) unsigned DEFAULT NULL,
            `size` bigint unsigned NOT NULL,
            `has_derivative_image` tinyint(1) NOT NULL,
            `authentication` char(32) collate utf8_unicode_ci default NULL,
            `mime_type` varchar(255) collate utf8_unicode_ci default NULL,
            `type_os` varchar(255) collate utf8_unicode_ci default NULL,
            `filename` text collate utf8_unicode_ci NOT NULL,
            `original_filename` text collate utf8_unicode_ci NOT NULL,
            `modified` timestamp NOT NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP,
            `added` timestamp NOT NULL DEFAULT '2000-01-01 00:00:00',
            `stored` tinyint(1) NOT NULL default '0',
            `metadata` mediumtext collate utf8_unicode_ci NOT NULL,
            PRIMARY KEY  (`id`),
            KEY `collection_id` (`collection_id`)
            ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
          $database->query($sql);
        
    }

    /**
     * Uninstall the plugin.
     */
    public function hookUninstall()
    {
        // Drop the Location table
        $database = get_db();
        $database->query("DROP TABLE IF EXISTS `$database->CollectionFile`");
    }

    /**
     * Display the uninstall message.
     */
    public function hookUninstallMessage()
    { ?>
        <?= __('%sWarning%s: This will remove all the collection files %s', '<p><strong>', '</strong>', '</p>'); ?> <?php
    }

    /**
     * Hook for admin head.
     *
     * @return void
     */
    public function hookAdminHead()
    {
        queue_css_file('collectionfiles');
        queue_js_file('collections');
    }

 
    public function filterAdminCollectionsFormTabs($tabs, $args)
    {
        $record = $args['collection'];
        $view = get_view();
        
        // Update the tab.
        $tabs['Files'] = $view->partial('file/file-input-partial-col.php', array(
            'collection' => $record,
            'has_files' => $this->collection_has_files($record),
            'files' => $this->get_collection_files($record),
        ));
        
        return $tabs;
    }
    
    private function collection_has_files($record){
        $database = get_db();
        $sql = "
        SELECT COUNT(f.id)
        FROM $database->CollectionFile f
        WHERE f.collection_id = ?";
        $has_files = (int) $database->fetchOne($sql, array((int) $record->id));
        
        return (bool) $has_files;
    }
    
    private function get_collection_files($record){
        $database = get_db();
        $files = $database->getTable('CollectionFile')->findByCollection($record->id);
        return $files;
    }
    
    public function hookBeforeSaveCollection($args){
        $collection = $args['record'];
        try {
            if ($this->isset_file('collectionfile')){
                $this->insert_files_for_collection($collection, 'Upload', 'collectionfile', array('ignoreNoFile' => true));
            }
        } catch (Omeka_File_Ingest_InvalidException $e) {
            $collection->addError('File Upload', $e->getMessage());
        }
    }
    
    protected function isset_file($name){
        return empty($name) ? false : isset($_FILES[$name]);
    }
    
    private function insert_files_for_collection($collection, $transferStrategy, $files, $options = array())
    {
        $builder = new Builder_CollectionFiles(get_db());
        $builder->setRecord($collection);
        $files = $builder->addFiles($transferStrategy, $files, $options);
        foreach ($files as $key => $file) {
            $file->collection_id = $collection->id;
            $file->save();
            // Make sure we can't save it twice by mistake.
            unset($files[$key]);
        }
    }
    
    public function hookAfterSaveCollection($args)
    {
        $database = get_db();
        if ($args['post']) {
            $post = $args['post'];
            $collection = $args['record'];
           
            // Update file order for this collection.
            if (isset($post['order'])) {
                foreach ($post['order'] as $fileId => $fileOrder) {
                    // File order must be an integer or NULL.
                    $fileOrder = (int) $fileOrder;
                    if (!$fileOrder) {
                        $fileOrder = null;
                    }
                    
                    $file = $database->getTable('CollectionFile')->find($fileId);
                    if($file){
                        $file->order = $fileOrder;
                        $file->save();
                    }
                }
            }
            
            // Delete files that have been designated by passing an array of IDs
            // through the form.
            if (isset($post['delete_files']) && ($files = $post['delete_files'])) {
                $filesToDelete = $database->getTable('CollectionFile')->findByCollection($collection->id, $files, 'id');
                foreach ($filesToDelete as $fileRecord) {
                    $fileRecord->delete();
                }
            }
        }
    }
    
    public function hookAdminCollectionsForm($args){ ?>
         <?= '<script type="text/javascript">
                jQuery(document).ready(function () {
                    Omeka.Collections.makeFileWindow();
                    Omeka.Collections.enableSorting();
                    Omeka.Collections.enableAddFiles('.js_escape(__('Add Another File')).');
                });
              </script>' ?>
        <?php
    }
    
    public function hookAdminCollectionsShowSidebar($args){
        $collection = $args['collection']; 
        
        $this->_p_html('<div class="panel">
        <h4>'.__('Collection Files').'</h4>
        <div id="file-list">');
        
        if (!$this->collection_has_files($collection)){ 
            $this->_p_html('<p>'.__('There are no files for this collection yet.').link_to_collection(__(' Add a File'), array(), 'edit').'.</p>'); 
        } else {
            $files = $this->get_collection_files($collection); 
            $this->_p_html('<ul>'); 
            foreach ($files as $file){ 
                 $this->_p_html(link_to($file,'show', $file->original_filename)); 
                 $this->_p_html("<br>"); 
            } 
            $this->_p_html('</ul>');     
        } 
        $this->_p_html('</div> </div>'); 
    }

    public function hookAdminCollectionsShow($args){
        $collection = $args['collection'];
        if ($this->collection_has_files($collection)){
            $files = $this->get_collection_files($collection);
            $this->_p_html('<h2>Collection Files</h2>');
            foreach ($files as $file){
                $this->_p_html(file_markup($file));
            }
        }
    }

    public function hookAdminCollectionsBrowseEach($args){
        $collection = $args['collection'];
        if ($this->collection_has_files($collection)){
            $database = get_db();
            $file = $database->getTable('CollectionFile')->findOneByCollection($collection->id, 0);
            $format = (get_option('use_square_thumbnail') == 1) ? 'square_thumbnail' : 'thumbnail';
            if ($file->hasThumbnail()) {
                $uri = $file->getWebPath($format);
            } else {
                $uri = img($this->_getFallbackImage($file));
            }
            $attrs = array();
            $attrs['src'] = $uri;
            $alt = '';
            if ($fileTitle = metadata($file, 'display title', array('no_escape' => true))) {
                $alt = $fileTitle;
            }
            $attrs['alt'] = $alt;
            $attrs['title'] = $alt;
            $attrs = apply_filters('image_tag_attributes', $attrs, array(
                'record' => $collection,
                'file' => $file,
                'format' => $format,
            ));
            $img = '<img ' . tag_attributes($attrs) . '>';
            $this->_p_html(link_to_collection($img, array('class' => 'image cf')));
        }
    }

    protected function _getFallbackImage($file)
    {
        $mimeType = $file->mime_type;
        if (isset(self::$_fallbackImages[$mimeType])) {
            return self::$_fallbackImages[$mimeType];
        }

        $mimePrefix = substr($mimeType, 0, strpos($mimeType, '/'));
        if (isset(self::$_fallbackImages[$mimePrefix])) {
            return self::$_fallbackImages[$mimePrefix];
        }

        return self::$_fallbackImages['*'];
    }

    public function hookadminCollectionsBrowse($args){
        $this->_p_html('
            <script type="text/javascript">
                jQuery(document).ready(function () {
                    jQuery("td.title a.cf").each(function( index ) {
                        jQuery(this).closest("td").prepend(jQuery(this));
                    });
                });
            </script>
        ');
    }
    
    private function _p_html($html){ ?>
      <?= $html ?> <?php
    }
}
