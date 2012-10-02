<?php
namespace Jackalope\Transport\DoctrineDBAL;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Connection;

/**
 * Class to handle setup the RDBMS tables for the Doctrine DBAL transport.
 *
 * @license http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0, January 2004
 */
class RepositorySchema
{
    static public function create()
    {
        $schema = new Schema();
        $namespace = $schema->createTable('phpcr_namespaces');
        $namespace->addColumn('prefix', 'string');
        $namespace->addColumn('uri', 'string');
        $namespace->setPrimaryKey(array('prefix'));

        $workspace = $schema->createTable('phpcr_workspaces');
        $workspace->addColumn('name', 'string');
        $workspace->setPrimaryKey(array('name'));

        // TODO increase the size of 'path' and 'parent' but this causes issues on MySQL due to key length
        $nodes = $schema->createTable('phpcr_nodes');
        $nodes->addColumn('id', 'integer', array('autoincrement' => true));
        $nodes->addColumn('path', 'string');
        $nodes->addColumn('parent', 'string');
        $nodes->addColumn('local_name', 'string');
        $nodes->addColumn('namespace', 'string');
        $nodes->addColumn('workspace_name', 'string');
        $nodes->addColumn('identifier', 'string');
        $nodes->addColumn('type', 'string');
        $nodes->addColumn('depth', 'integer');
        $nodes->addColumn('props', 'text');
        $nodes->addColumn('sort_order', 'integer', array('notnull' => false));
        $nodes->setPrimaryKey(array('id'));
        $nodes->addUniqueIndex(array('path', 'workspace_name'));
        $nodes->addUniqueIndex(array('identifier'));
        $nodes->addIndex(array('parent'));
        $nodes->addIndex(array('type'));
        $nodes->addIndex(array('local_name', 'namespace'));

        $indexJcrTypes = $schema->createTable('phpcr_internal_index_types');
        $indexJcrTypes->addColumn('type', 'string');
        $indexJcrTypes->addColumn('node_id', 'integer');
        $indexJcrTypes->setPrimaryKey(array('type', 'node_id'));

        $binary = $schema->createTable('phpcr_binarydata');
        $binary->addColumn('id', 'integer', array('autoincrement' => true));
        $binary->addColumn('node_id', 'integer');
        $binary->addColumn('property_name', 'string');
        $binary->addColumn('workspace_name', 'string');
        $binary->addColumn('idx', 'integer', array('default' => 0));
        $binary->addColumn('data', 'blob');
        $binary->setPrimaryKey(array('id'));
        $binary->addUniqueIndex(array('node_id', 'property_name', 'workspace_name', 'idx'));

        $foreignKeys = $schema->createTable('phpcr_nodes_foreignkeys');
        $foreignKeys->addColumn('source_id', 'integer');
        $foreignKeys->addColumn('source_property_name', 'string', array('length' => 220));
        $foreignKeys->addColumn('target_id', 'integer');
        $foreignKeys->addColumn('type', 'smallint');
        $foreignKeys->setPrimaryKey(array('source_id', 'source_property_name', 'target_id'));
        $foreignKeys->addIndex(array('target_id'));
        $foreignKeys->addForeignKeyConstraint($nodes, array('source_id'), array('id'), array('onDelete' => 'CASCADE'));
        $foreignKeys->addForeignKeyConstraint($nodes, array('target_id'), array('id'), array('onDelete' => 'CASCADE'));

        $types = $schema->createTable('phpcr_type_nodes');
        $types->addColumn('node_type_id', 'integer', array('autoincrement' => true));
        $types->addColumn('name', 'string', array('unique' => true));
        $types->addColumn('supertypes', 'string');
        $types->addColumn('is_abstract', 'boolean');
        $types->addColumn('is_mixin', 'boolean');
        $types->addColumn('queryable', 'boolean');
        $types->addColumn('orderable_child_nodes', 'boolean');
        $types->addColumn('primary_item', 'string', array('notnull' => false));
        $types->setPrimaryKey(array('node_type_id'));

        $propTypes = $schema->createTable('phpcr_type_props');
        $propTypes->addColumn('node_type_id', 'integer');
        $propTypes->addColumn('name', 'string');
        $propTypes->addColumn('protected', 'boolean');
        $propTypes->addColumn('auto_created', 'boolean');
        $propTypes->addColumn('mandatory', 'boolean');
        $propTypes->addColumn('on_parent_version', 'integer');
        $propTypes->addcolumn('multiple', 'boolean');
        $propTypes->addColumn('fulltext_searchable', 'boolean');
        $propTypes->addcolumn('query_orderable', 'boolean');
        $propTypes->addColumn('required_type', 'integer');
        $propTypes->addColumn('query_operators', 'integer'); // BITMASK
        $propTypes->addColumn('default_value', 'string', array('notnull' => false));
        $propTypes->setPrimaryKey(array('node_type_id'));

        $childTypes = $schema->createTable('phpcr_type_childs');
        $childTypes->addColumn('node_type_id', 'integer');
        $childTypes->addColumn('name', 'string');
        $childTypes->addColumn('protected', 'boolean');
        $childTypes->addColumn('auto_created', 'boolean');
        $childTypes->addColumn('mandatory', 'boolean');
        $childTypes->addColumn('on_parent_version', 'integer');
        $childTypes->addColumn('primary_types', 'string');
        $childTypes->addColumn('default_type', 'string', array('notnull' => false));

        return $schema;
    }

    /**
     * @var Connection
     */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function reset()
    {
        $schema = self::create();

        $this->connection->getWrappedConnection()->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        foreach ($schema->toDropSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->exec($sql);
        }

        $this->connection->getWrappedConnection()->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        foreach ($schema->toSql($this->connection->getDatabasePlatform()) as $sql) {
            $this->connection->exec($sql);
        }
    }

}
