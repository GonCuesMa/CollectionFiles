<?php
/**
 * Collection Files plugin.
 *
 * @package Omeka
 * @copyright Roy Rosenzweig Center for History and New Media, 2007-2011
 */
class CollectionFiles_CollectionFilesMainTest extends CollectionFiles_Test_AppTestCase
{
    protected $_isAdminTest = true;

    public function testCanInsertFilesForCollections()
    {
        $collection = $this->_createOneCollection();
        $fileUrl = TEST_DIR . '/_files/test.txt';
        $files = $this->insert_files_for_collection($collection, 'Filesystem', array($fileUrl));
        $this->assertEquals(1, count($files));
        $this->assertThat($files[0], $this->isInstanceOf('CollectionFile'));
        $this->assertTrue($files[0]->exists());
        $this->assertEquals(1, total_records('CollectionFile'));
        $this->assertEquals($collection->id, $files[0]->collection_id);
    }
}
