<?php

namespace ZF2EntityAudit\EventListener;

use Doctrine\ORM\Tools\ToolEvents;
use ZF2EntityAudit\Audit\Manager;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\Common\EventSubscriber;

class CreateSchemaListener implements EventSubscriber
{
    /**
     * @var \ZF2EntityAudit\Audit\Configuration
     */
    private $config;

    /**
     * @var \ZF2EntityAudit\Metadata\MetadataFactory
     */
    private $metadataFactory;

    public function __construct(Manager $auditManager)
    {
        $this->config = $auditManager->getConfiguration();
        $this->metadataFactory = $auditManager->getMetadataFactory();
    }

    public function getSubscribedEvents()
    {
        return array(
            ToolEvents::postGenerateSchemaTable,
            ToolEvents::postGenerateSchema,
        );
    }

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $eventArgs)
    {
        $schema = $eventArgs->getSchema();
        $cm = $eventArgs->getClassMetadata();
        if ($this->metadataFactory->isAudited($cm->name)) {
            $schema = $eventArgs->getSchema();
            $entityTable = $eventArgs->getClassTable();
            $revisionTable = $schema->createTable(
                $this->config->getTablePrefix().$entityTable->getName().$this->config->getTableSuffix()
            );
            foreach ($entityTable->getColumns() AS $column) {
                /* @var $column Column */
                $revisionTable->addColumn($column->getName(), $column->getType()->getName(), array_merge(
                    $column->toArray(),
                    array('notnull' => false, 'autoincrement' => false)
                ));
            }
            $revisionTable->addColumn($this->config->getRevisionFieldName(), $this->config->getRevisionIdFieldType());
            $revisionTable->addColumn($this->config->getRevisionTypeFieldName(), 'string', array('length' => 4));
            $pkColumns = $entityTable->getPrimaryKey()->getColumns();
            $pkColumns[] = $this->config->getRevisionFieldName();
            $revisionTable->setPrimaryKey($pkColumns);
        }
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $eventArgs)
    {
        $schema = $eventArgs->getSchema();
        
        //get the entity meta
        $meta = $eventArgs->getEntityManager()->getClassMetadata($this->config->getZfcUserEntityClass());  

        //get the table name from the entity
        $revisionsTable = $schema->createTable($this->config->getRevisionTableName());
        
        $revisionsTable->addColumn('id', $this->config->getRevisionIdFieldType(), array(
            'autoincrement' => true,
        ));
        $revisionsTable->addColumn('timestamp', 'datetime');
        $revisionsTable->addColumn('note', 'text', array('notnull' => false));
        $revisionsTable->addColumn('ipaddress', 'text', array('notnull' => false));

        $localColumnNames = array();
        $foreignColumnNames = array();
        foreach($meta->getIdentifier() as $primaryKey) {
            $columnName = $meta->getColumnName($primaryKey);
            $foreignColumnNames[] = $columnName;

            $columnName = preg_replace('/user[^a-zA-Z0-9]*/', '', $columnName);

            $localColumnName = 'user_id'; // . $columnName;
            $localColumnNames[] = $localColumnName;

            $fieldType = $meta->getTypeOfField($primaryKey);
            $revisionsTable->addColumn($localColumnName, $fieldType, array('notnull' => false,'unsigned' => true));
        }

        //add the tablename and primary key from the entity meta
        $revisionsTable->addForeignKeyConstraint($meta->getTableName(), $localColumnNames, $foreignColumnNames);
        $revisionsTable->setPrimaryKey(array('id'));
    }
}
