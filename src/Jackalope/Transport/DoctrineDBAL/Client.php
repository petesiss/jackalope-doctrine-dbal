<?php

namespace Jackalope\Transport\DoctrineDBAL;

use PHPCR\PropertyType;
use PHPCR\Query\QOM\QueryObjectModelInterface;
use PHPCR\Query\QOM\SelectorInterface;
use PHPCR\Query\QueryInterface;
use PHPCR\RepositoryException;
use PHPCR\NamespaceException;
use PHPCR\NamespaceRegistryInterface;
use PHPCR\RepositoryInterface;
use PHPCR\Util\UUIDHelper;
use PHPCR\Util\QOM\Sql2ToQomQueryConverter;
use PHPCR\NoSuchWorkspaceException;
use PHPCR\ItemExistsException;
use PHPCR\ItemNotFoundException;
use PHPCR\ReferentialIntegrityException;
use PHPCR\ValueFormatException;
use PHPCR\PathNotFoundException;
use PHPCR\Query\InvalidQueryException;
use PHPCR\NodeType\ConstraintViolationException;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDOConnection;
use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Platforms\PostgreSqlPlatform;
use Doctrine\DBAL\Platforms\SqlitePlatform;
use Doctrine\DBAL\DBALException;

use Jackalope\Node;
use Jackalope\Property;
use Jackalope\Query\Query;
use Jackalope\Transport\BaseTransport;
use Jackalope\Transport\QueryInterface as QueryTransport;
use Jackalope\Transport\WritingInterface;
use Jackalope\Transport\WorkspaceManagementInterface;
use Jackalope\Transport\NodeTypeManagementInterface;
use Jackalope\Transport\TransactionInterface;
use Jackalope\Transport\StandardNodeTypes;
use Jackalope\Transport\DoctrineDBAL\Query\QOMWalker;
use Jackalope\NodeType\NodeTypeManager;
use Jackalope\NodeType\NodeType;
use Jackalope\NotImplementedException;
use Jackalope\FactoryInterface;

/**
 * Class to handle the communication between Jackalope and RDBMS via Doctrine DBAL.
 *
 * @license http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0, January 2004
 *
 * @author Benjamin Eberlei <kontakt@beberlei.de>
 * @author Lukas Kahwe Smith <smith@pooteeweet.org>
 */
class Client extends BaseTransport implements QueryTransport, WritingInterface, WorkspaceManagementInterface, NodeTypeManagementInterface, TransactionInterface
{
    /**
     * @var Doctrine\DBAL\Connection
     */
    private $conn;

    /**
     * @var bool
     */
    private $loggedIn = false;

    /**
     * @var \PHPCR\SimpleCredentials
     */
    private $credentials;

    /**
     * @var string
     */
    protected $workspaceName;

    /**
     * @var array
     */
    private $nodeIdentifiers = array();

    /**
     * @var NodeTypeManager
     */
    private $nodeTypeManager;

    /**
     * @var bool
     */
    protected $inTransaction = false;

    /**
     * Check if an initial request on login should be send to check if repository exists
     * This is according to the JCR specifications and set to true by default
     * @see setCheckLoginOnServer
     * @var bool
     */
    private $checkLoginOnServer = true;

    /**
     * @var array
     */
    protected $namespaces = array();

    /**
     * @var string|null
     */
    private $sequenceWorkspaceName;

    /**
     * @var string|null
     */
    private $sequenceNodeName;

    /**
     * @var string|null
     */
    private $sequenceTypeName;

    public function __construct(FactoryInterface $factory, Connection $conn)
    {
        $this->factory = $factory;
        $this->conn = $conn;
        if ($conn->getDatabasePlatform() instanceof PostgreSqlPlatform) {
            $this->sequenceWorkspaceName = 'phpcr_workspaces_id_seq';
            $this->sequenceNodeName = 'phpcr_nodes_id_seq';
            $this->sequenceTypeName = 'phpcr_type_nodes_node_type_id_seq';
        }

        // @TODO: move to "SqlitePlatform" and rename to "registerExtraFunctions"?
        if ($this->conn->getDatabasePlatform() instanceof SqlitePlatform) {
            $this->registerSqliteFunctions($this->conn->getWrappedConnection());
        }
    }

    /**
     * @TODO: move to "SqlitePlatform" and rename to "registerExtraFunctions"?
     *
     * @param PDOConnection $sqliteConnection
     *
     * @return Client
     */
    private function registerSqliteFunctions(PDOConnection $sqliteConnection)
    {
        $sqliteConnection->sqliteCreateFunction('EXTRACTVALUE', function ($string, $expression) {
            $dom = new \DOMDocument('1.0', 'UTF-8');
            $dom->loadXML($string);
            $xpath = new \DOMXPath($dom);
            $list = $xpath->evaluate($expression);

            if (!is_object($list)) {
                return $list;
            }

            // @TODO: don't know if there are expressions returning more then one row
            if ($list->length > 0) {
                return $list->item(0)->textContent;
            }

            // @TODO: don't know if return value is right
            return null;
        }, 2);

        $sqliteConnection->sqliteCreateFunction('CONCAT', function () {
            return implode('', func_get_args());
        });

        return $this;
    }

    /**
     * @return Doctrine\DBAL\Connection
     */
    public function getConnection()
    {
        return $this->conn;
    }

    /**
     * {@inheritDoc}
     *
     */
    public function createWorkspace($name, $srcWorkspace = null)
    {
        if (null !== $srcWorkspace) {
            throw new NotImplementedException();
        }

        try {
            $this->conn->insert('phpcr_workspaces', array('name' => $name));
        } catch (\Exception $e) {
            throw new RepositoryException("Workspace '$name' already exists");
        }

        $this->conn->insert('phpcr_nodes', array(
            'path'          => '/',
            'parent'        => '',
            'workspace_name'=> $name,
            'identifier'    => UUIDHelper::generateUUID(),
            'type'          => 'nt:unstructured',
            'local_name'    => '',
            'namespace'     => '',
            'props' => '<?xml version="1.0" encoding="UTF-8"?>
<sv:node xmlns:mix="http://www.jcp.org/jcr/mix/1.0" xmlns:nt="http://www.jcp.org/jcr/nt/1.0" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:jcr="http://www.jcp.org/jcr/1.0" xmlns:sv="http://www.jcp.org/jcr/sv/1.0" xmlns:rep="internal" />',
            // TODO compute proper value
            'depth'         => 0,
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function login(\PHPCR\CredentialsInterface $credentials = null, $workspaceName = 'default')
    {
        $this->credentials = $credentials;
        $this->workspaceName = $workspaceName;

        if (!$this->checkLoginOnServer) {
            return true;
        }

        if (!$this->workspaceExists($workspaceName)) {
            if ('default' !== $workspaceName) {
                throw new NoSuchWorkspaceException("Requested workspace: $workspaceName");
            }

            // create default workspace if it not exists
            $this->createWorkspace($workspaceName);
        }

        $this->loggedIn = true;

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function logout()
    {
        if ($this->loggedIn) {
            $this->loggedIn = false;
            $this->conn->close();
            $this->conn = null;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function setCheckLoginOnServer($bool)
    {
        $this->checkLoginOnServer = $bool;
    }

    protected function workspaceExists($workspaceName)
    {
        try {
            $query = 'SELECT 1 FROM phpcr_workspaces WHERE name = ?';
            $result = $this->conn->fetchColumn($query, array($workspaceName));
        } catch (\Exception $e) {
            if ($e instanceof DBALException || $e instanceof \PDOException) {
                if (1045 == $e->getCode()) {
                    throw new \PHPCR\LoginException('Access denied with your credentials: '.$e->getMessage());
                }
                if ('42S02' == $e->getCode()) {
                    throw new \PHPCR\RepositoryException('You did not properly set up the database for the repository. See README.md for more information. Message from backend: '.$e->getMessage());
                }

                throw new \PHPCR\RepositoryException('Unexpected error talking to the backend: '.$e->getMessage());
            }

            throw $e;
        }

        return $result;
    }

    protected function assertLoggedIn()
    {
        if (!$this->loggedIn) {
            if (!$this->checkLoginOnServer && $this->workspaceName) {
                $this->checkLoginOnServer = true;
                if ($this->login($this->credentials, $this->workspaceName)) {
                    return;
                }
            }

            throw new RepositoryException('You need to be logged in for this operation');
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getRepositoryDescriptors()
    {
        return array(
            RepositoryInterface::IDENTIFIER_STABILITY => RepositoryInterface::IDENTIFIER_STABILITY_INDEFINITE_DURATION,
            RepositoryInterface::REP_NAME_DESC  => 'jackalope_doctrine_dbal',
            RepositoryInterface::REP_VENDOR_DESC => 'Jackalope Community',
            RepositoryInterface::REP_VENDOR_URL_DESC => 'http://github.com/jackalope',
            RepositoryInterface::REP_VERSION_DESC => '1.0.0-DEV',
            RepositoryInterface::SPEC_NAME_DESC => 'Content Repository for PHP',
            RepositoryInterface::SPEC_VERSION_DESC => '2.1',
            RepositoryInterface::NODE_TYPE_MANAGEMENT_AUTOCREATED_DEFINITIONS_SUPPORTED => true,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_INHERITANCE => RepositoryInterface::NODE_TYPE_MANAGEMENT_INHERITANCE_SINGLE,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_MULTIPLE_BINARY_PROPERTIES_SUPPORTED => true,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_MULTIVALUED_PROPERTIES_SUPPORTED => true,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_ORDERABLE_CHILD_NODES_SUPPORTED => true,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_OVERRIDES_SUPPORTED => false,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_PRIMARY_ITEM_NAME_SUPPORTED => true,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_PROPERTY_TYPES => true,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_RESIDUAL_DEFINITIONS_SUPPORTED => false,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_SAME_NAME_SIBLINGS_SUPPORTED => false,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_UPDATE_IN_USE_SUPPORTED => false,
            RepositoryInterface::NODE_TYPE_MANAGEMENT_VALUE_CONSTRAINTS_SUPPORTED => false,
            RepositoryInterface::OPTION_ACCESS_CONTROL_SUPPORTED => false,
            RepositoryInterface::OPTION_ACTIVITIES_SUPPORTED => false,
            RepositoryInterface::OPTION_BASELINES_SUPPORTED => false,
            RepositoryInterface::OPTION_JOURNALED_OBSERVATION_SUPPORTED => false,
            RepositoryInterface::OPTION_LIFECYCLE_SUPPORTED => false,
            RepositoryInterface::OPTION_LOCKING_SUPPORTED => false,
            RepositoryInterface::OPTION_NODE_AND_PROPERTY_WITH_SAME_NAME_SUPPORTED => true,
            RepositoryInterface::OPTION_NODE_TYPE_MANAGEMENT_SUPPORTED => true,
            RepositoryInterface::OPTION_OBSERVATION_SUPPORTED => false,
            RepositoryInterface::OPTION_RETENTION_SUPPORTED => false,
            RepositoryInterface::OPTION_SHAREABLE_NODES_SUPPORTED => false,
            RepositoryInterface::OPTION_SIMPLE_VERSIONING_SUPPORTED => false,
            RepositoryInterface::OPTION_TRANSACTIONS_SUPPORTED => true,
            RepositoryInterface::OPTION_UNFILED_CONTENT_SUPPORTED => true,
            RepositoryInterface::OPTION_UPDATE_MIXIN_NODETYPES_SUPPORTED => true,
            RepositoryInterface::OPTION_UPDATE_PRIMARY_NODETYPE_SUPPORTED => true,
            RepositoryInterface::OPTION_VERSIONING_SUPPORTED => false,
            RepositoryInterface::OPTION_WORKSPACE_MANAGEMENT_SUPPORTED => true,
            RepositoryInterface::OPTION_XML_EXPORT_SUPPORTED => true,
            RepositoryInterface::OPTION_XML_IMPORT_SUPPORTED => true,
            RepositoryInterface::QUERY_FULL_TEXT_SEARCH_SUPPORTED => false,
            RepositoryInterface::QUERY_JOINS => RepositoryInterface::QUERY_JOINS_NONE,
            RepositoryInterface::QUERY_LANGUAGES => array(QueryInterface::JCR_SQL2, QueryInterface::JCR_JQOM),
            RepositoryInterface::QUERY_STORED_QUERIES_SUPPORTED => false,
            RepositoryInterface::WRITE_SUPPORTED => true,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getNamespaces()
    {
        if (empty($this->namespaces)) {
            $query = 'SELECT * FROM phpcr_namespaces';
            $data = $this->conn->fetchAll($query);

            $this->namespaces = array(
                NamespaceRegistryInterface::PREFIX_EMPTY => NamespaceRegistryInterface::NAMESPACE_EMPTY,
                NamespaceRegistryInterface::PREFIX_JCR => NamespaceRegistryInterface::NAMESPACE_JCR,
                NamespaceRegistryInterface::PREFIX_NT => NamespaceRegistryInterface::NAMESPACE_NT,
                NamespaceRegistryInterface::PREFIX_MIX => NamespaceRegistryInterface::NAMESPACE_MIX,
                NamespaceRegistryInterface::PREFIX_XML => NamespaceRegistryInterface::NAMESPACE_XML,
                NamespaceRegistryInterface::PREFIX_SV => NamespaceRegistryInterface::NAMESPACE_SV,
                'phpcr' => 'http://github.com/jackalope/jackalope', // TODO: Namespace?
            );

            foreach ($data as $row) {
                $this->namespaces[$row['prefix']] = $row['uri'];
            }
        }

        return $this->namespaces;
    }

    /**
     * {@inheritDoc}
     */
    public function copyNode($srcAbsPath, $dstAbsPath, $srcWorkspace = null)
    {
        $this->assertLoggedIn();

        $workspaceName = $this->workspaceName;
        if (null !== $srcWorkspace) {
            if (!$this->workspaceExists($srcWorkspace)) {
                throw new NoSuchWorkspaceException("Source workspace '$srcWorkspace' does not exist.");
            }
        }

        $this->assertValidPath($dstAbsPath, true);

        $srcNodeId = $this->pathExists($srcAbsPath);
        if (!$srcNodeId) {
            throw new PathNotFoundException("Source path '$srcAbsPath' not found");
        }

        if ($this->pathExists($dstAbsPath)) {
            throw new ItemExistsException("Cannot copy to destination path '$dstAbsPath' that already exists.");
        }

        if (!$this->pathExists($this->getParentPath($dstAbsPath))) {
            throw new PathNotFoundException("Parent of the destination path '" . $this->getParentPath($dstAbsPath) . "' has to exist.");
        }

        // Algorithm:
        // 1. Select all nodes with path $srcAbsPath."%" and iterate them
        // 2. create a new node with path $dstAbsPath + leftovers, with a new uuid. Save old => new uuid
        // 3. copy all properties from old node to new node
        // 4. if a reference is in the properties, either update the uuid based on the map if its inside the copied graph or keep it.
        // 5. "May drop mixin types"

        try {
            $this->conn->beginTransaction();

            $query = 'SELECT * FROM phpcr_nodes WHERE path LIKE ? AND workspace_name = ?';
            $stmt = $this->conn->executeQuery($query, array($srcAbsPath . '%', $workspaceName));

            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $newPath = str_replace($srcAbsPath, $dstAbsPath, $row['path']);

                $dom = new \DOMDocument('1.0', 'UTF-8');
                $dom->loadXML($row['props']);

                $propsData = array('dom' => $dom, 'binaryData' => array());
                //when copying a node, it is always a new node, then $isNewNode is set to true
                $newNodeId = $this->syncNode(null, $newPath, $this->getParentPath($newPath), $row['type'], true, substr_count($newPath, "/"), array(), $propsData);

                $query = 'INSERT INTO phpcr_binarydata (node_id, property_name, workspace_name, idx, data)'.
                    '   SELECT ?, b.property_name, ?, b.idx, b.data FROM phpcr_binarydata b WHERE b.node_id = ?';
                $this->conn->executeUpdate($query, array($newNodeId, $this->workspaceName, $srcNodeId));
            }

            $this->conn->commit();
        } catch (\Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    /**
     * @param string $path
     * @return array
     */
    private function getJcrName($path)
    {
        $name = implode('', array_slice(explode('/', $path), -1, 1));
        if (strpos($name, ':') === false) {
            $alias = '';
        } else {
            list($alias, $name) = explode(':', $name);
        }

        $namespaces = $this->getNamespaces();

        if (!isset($namespaces[$alias])) {
            throw new NamespaceException('the namespace ' . $alias . ' was not registered.');
        }

        return array($namespaces[$alias], $name);
    }

    /**
     * @param string $uuid node uuid
     * @param string $path absolute path of the node
     * @param string $parent absolute path of the parent node
     * @param string $type node type
     * @param bool $isNewNode new nodes to insert (true) or existing node to update (false)
     * @param array $props
     * @param array $propsData
     *
     * @return bool|mixed|string
     *
     * @throws \Exception|\PHPCR\ItemExistsException|\PHPCR\RepositoryException
     */
    private function syncNode($uuid, $path, $parent, $type, $isNewNode, $depth, $props = array(), $propsData = array())
    {
        // TODO: Not sure if there are always ALL props in $props, should we grab the online data here?
        // TODO: Binary data is handled very inefficiently here, UPSERT will really be necessary here as well as lazy handling

        $this->conn->beginTransaction();

        try {
            if (!$propsData) {
                $propsData = $this->propsToXML($props);
            }

            if (null === $uuid) {
                $uuid = UUIDHelper::generateUUID();
            }

            if ($isNewNode) {
                list($namespace, $localName) = $this->getJcrName($path);

                $qb = $this->conn->createQueryBuilder();

                $qb->select(':identifier, :type, :path, :local_name, :namespace, :parent, :workspace_name, :props, :depth, COALESCE(MAX(n.sort_order), 0) + 1')
                   ->from('phpcr_nodes', 'n')
                   ->where('n.parent = :parent_a');

                $sql = $qb->getSql();

                try {
                    $insert = "INSERT INTO phpcr_nodes (identifier, type, path, local_name, namespace, parent, workspace_name, props, depth, sort_order) " . $sql;
                    $this->conn->executeUpdate($insert, array(
                        'identifier'    => $uuid,
                        'type'          => $type,
                        'path'          => $path,
                        'local_name'    => $localName,
                        'namespace'     => $namespace,
                        'parent'        => $parent,
                        'workspace_name'  => $this->workspaceName,
                        'props'         => $propsData['dom']->saveXML(),
                        'depth'         => $depth,
                        'parent_a'      => $parent,
                    ));
                } catch (\PDOException $e) {
                    throw new ItemExistsException('Item ' . $path . ' already exists in the database');
                } catch (DBALException $e) {
                    throw new ItemExistsException('Item ' . $path . ' already exists in the database');
                }

                $nodeId = $this->conn->lastInsertId($this->sequenceNodeName);
            } else {
                $nodeId = $this->pathExists($path);
                if (!$nodeId) {
                    throw new RepositoryException();
                }
                $this->conn->update('phpcr_nodes', array('props' => $propsData['dom']->saveXML()), array('id' => $nodeId));
            }

            $this->nodeIdentifiers[$path] = $uuid;

            if (isset($propsData['binaryData'])) {
                $this->syncBinaryData($nodeId, $propsData['binaryData']);
            }

            // update foreign keys (references)
            $this->syncForeignKeys($nodeId, $path, $props);

            // Update internal indexes
            $this->syncInternalIndexes();
            // Update user indexes
            $this->syncUserIndexes();

            $this->conn->commit();
        } catch (\Exception $e) {
            $this->conn->rollback();
            throw $e;
        }

        return $nodeId;
    }

    private function syncInternalIndexes()
    {
        // TODO implement syncInternalIndexes()
    }

    private function syncUserIndexes()
    {
        // TODO implement syncUserIndexes()
    }

    private function syncBinaryData($nodeId, $binaryData)
    {
        foreach ($binaryData as $propertyName => $binaryValues) {
            foreach ($binaryValues as $idx => $data) {
                // TODO verify in which cases we can just update
                $params = array(
                    'node_id'       => $nodeId,
                    'property_name' => $propertyName,
                    'workspace_name'  => $this->workspaceName,
                );
                $this->conn->delete('phpcr_binarydata', $params);

                $params['idx'] = $idx;
                $params['data'] = $data;
                $types = array(
                    \PDO::PARAM_INT,
                    \PDO::PARAM_STR,
                    \PDO::PARAM_STR,
                    \PDO::PARAM_INT,
                    \PDO::PARAM_LOB
                );
                $this->conn->insert('phpcr_binarydata', $params, $types);
        }
        }
    }

    private function syncForeignKeys($nodeId, $path, $props)
    {
        $this->conn->delete('phpcr_nodes_foreignkeys', array('source_id' => $nodeId));

        foreach ($props as $property) {
            $type = $property->getType();
            if (PropertyType::REFERENCE == $type || PropertyType::WEAKREFERENCE == $type) {
                $values = array_unique( $property->isMultiple() ? $property->getString() : array($property->getString()) );

                foreach ($values as $value) {
                    try {
                        $targetId = $this->pathExists(self::getNodePathForIdentifier($value));

                        $this->conn->insert('phpcr_nodes_foreignkeys', array(
                            'source_id' => $nodeId,
                            'source_property_name' => $property->getName(),
                            'target_id' => $targetId,
                            'type' => $type
                        ));
                    } catch (ItemNotFoundException $e) {
                        if (PropertyType::REFERENCE == $type) {
                            throw new ReferentialIntegrityException(
                                "Trying to store reference to non-existant node with path '$value' in node $path property " . $property->getName()
                            );
                        }
                    }
                }
            }
        }
    }

    static public function xmlToProps($xml, $filter = null)
    {
        $props = array();

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadXML($xml);

        foreach ($dom->getElementsByTagNameNS('http://www.jcp.org/jcr/sv/1.0', 'property') as $propertyNode) {
            $name = $propertyNode->getAttribute('sv:name');
            $values = array();
            $type = PropertyType::valueFromName($propertyNode->getAttribute('sv:type'));
            foreach ($propertyNode->childNodes as $valueNode) {
                switch ($type) {
                    case PropertyType::NAME:
                    case PropertyType::URI:
                    case PropertyType::WEAKREFERENCE:
                    case PropertyType::REFERENCE:
                    case PropertyType::PATH:
                    case PropertyType::DECIMAL:
                    case PropertyType::STRING:
                        $values[] = $valueNode->nodeValue;
                        break;
                    case PropertyType::BOOLEAN:
                        $values[] = (bool)$valueNode->nodeValue;
                        break;
                    case PropertyType::LONG:
                        $values[] = (int)$valueNode->nodeValue;
                        break;
                    case PropertyType::BINARY:
                        $values[] = (int)$valueNode->nodeValue;
                        break;
                    case PropertyType::DATE:
                        $values[] = $valueNode->nodeValue;
                        break;
                    case PropertyType::DOUBLE:
                        $values[] = (double)$valueNode->nodeValue;
                        break;
                    default:
                        throw new \InvalidArgumentException("Type with constant $type not found.");
                }
            }

            // only return the properties that pass through the filter callback
            if (null !== $filter && is_callable($filter)) {
                if (false === $filter($name, $values)) {
                    continue;
                }
            }

            if (PropertyType::BINARY == $type) {
                if (1 == $propertyNode->getAttribute('sv:multi-valued')) {
                    $props[':' . $name] = $values;
                } else {
                    $props[':' . $name] = $values[0];
                }
            } else {
                if (1 == $propertyNode->getAttribute('sv:multi-valued')) {
                    $props[$name] = $values;
                } else {
                    $props[$name] = $values[0];
                }
                $props[':' . $name] = $type;
            }
        }

        return $props;
    }

    /**
     * Seperate properties array into an xml and binary data.
     *
     * @param array $properties
     * @param bool $inlineBinaries
     * @return array ('dom' => $dom, 'binary' => streams)
     */
    static public function propsToXML($properties, $inlineBinaries = false)
    {
        $namespaces = array(
            'mix' => "http://www.jcp.org/jcr/mix/1.0",
            'nt' => "http://www.jcp.org/jcr/nt/1.0",
            'xs' => "http://www.w3.org/2001/XMLSchema",
            'jcr' => "http://www.jcp.org/jcr/1.0",
            'sv' => "http://www.jcp.org/jcr/sv/1.0",
            'rep' => "internal"
        );

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $rootNode = $dom->createElement('sv:node');
        foreach ($namespaces as $namespace => $uri) {
            $rootNode->setAttribute('xmlns:' . $namespace, $uri);
        }
        $dom->appendChild($rootNode);

        $binaryData = null;
        foreach ($properties as $property) {
            /* @var $property Property */
            $propertyNode = $dom->createElement('sv:property');
            $propertyNode->setAttribute('sv:name', $property->getName());
            $propertyNode->setAttribute('sv:type', PropertyType::nameFromValue($property->getType()));
            $propertyNode->setAttribute('sv:multi-valued', $property->isMultiple() ? '1' : '0');

            switch ($property->getType()) {
                case PropertyType::NAME:
                case PropertyType::URI:
                case PropertyType::WEAKREFERENCE:
                case PropertyType::REFERENCE:
                case PropertyType::PATH:
                case PropertyType::STRING:
                    $values = $property->getString();
                    break;
                case PropertyType::DECIMAL:
                    $values = $property->getDecimal();
                    break;
                case PropertyType::BOOLEAN:
                    $values = array_map('intval', (array) $property->getBoolean());
                    break;
                case PropertyType::LONG:
                    $values = $property->getLong();
                    break;
                case PropertyType::BINARY:
                    if ($property->isNew() || $property->isModified()) {
                        if ($property->isMultiple()) {
                            $values = array();
                            foreach ($property->getValueForStorage() as $stream) {
                                if (null === $stream) {
                                    $binary = '';
                                } else {
                                    $binary = stream_get_contents($stream);
                                    fclose($stream);
                                }
                                $binaryData[$property->getName()][] = $binary;
                                $values[] = strlen($binary);
                            }
                        } else {
                            $stream = $property->getValueForStorage();
                            if (null === $stream) {
                                $binary = '';
                            } else {
                                $binary = stream_get_contents($stream);
                                fclose($stream);
                            }
                            $binaryData[$property->getName()][] = $binary;
                            $values = strlen($binary);
                        }
                    } else {
                        $values = $property->getLength();
                        if (!$property->isMultiple() && empty($values)) {
                            // TODO: not sure why this happens.
                            $values = array(0);
                        }
                    }
                    break;
                case PropertyType::DATE:
                    $date = $property->getDate();
                    if (!$date instanceof \DateTime) {
                        $date = new \DateTime("now");
                    }
                    $values = $date->format('r');
                    break;
                case PropertyType::DOUBLE:
                    $values = $property->getDouble();
                    break;
                default:
                    throw new RepositoryException('unknown type '.$property->getType());
            }

            foreach ((array)$values as $value) {
                $propertyNode->appendChild($dom->createElement('sv:value', $value));
            }

            $rootNode->appendChild($propertyNode);
        }

        return array('dom' => $dom, 'binaryData' => $binaryData);
    }

    /**
     * {@inheritDoc}
     */
    public function getAccessibleWorkspaceNames()
    {
        $query = "SELECT DISTINCT name FROM phpcr_workspaces";
        $stmt = $this->conn->executeQuery($query);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * {@inheritDoc}
     */
    public function getNode($path)
    {
        $this->assertValidPath($path);
        $this->assertLoggedIn();

        $query = 'SELECT * FROM phpcr_nodes WHERE path = ? AND workspace_name = ?';
        $row = $this->conn->fetchAssoc($query, array($path, $this->workspaceName));
        if (!$row) {
            throw new ItemNotFoundException("Item $path not found in workspace ".$this->workspaceName);
        }

        return $this->getNodeData($path, $row);
    }

    private function getNodeData($path, $row)
    {
        $data = new \stdClass();
        $data->{'jcr:primaryType'} = $row['type'];
        $this->nodeIdentifiers[$path] = $row['identifier'];

        $query = 'SELECT path FROM phpcr_nodes WHERE parent = ? AND workspace_name = ? ORDER BY sort_order ASC';
        $children = $this->conn->fetchAll($query, array($path, $this->workspaceName));
        foreach ($children as $child) {
            $childName = explode('/', $child['path']);
            $childName = end($childName);
            $data->{$childName} = new \stdClass();
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadXML($row['props']);

        foreach ($dom->getElementsByTagNameNS('http://www.jcp.org/jcr/sv/1.0', 'property') as $propertyNode) {
            $name = $propertyNode->getAttribute('sv:name');
            $values = array();
            $type = PropertyType::valueFromName($propertyNode->getAttribute('sv:type'));
            foreach ($propertyNode->childNodes as $valueNode) {
                switch ($type) {
                    case PropertyType::NAME:
                    case PropertyType::URI:
                    case PropertyType::WEAKREFERENCE:
                    case PropertyType::REFERENCE:
                    case PropertyType::PATH:
                    case PropertyType::DECIMAL:
                    case PropertyType::STRING:
                        $values[] = $valueNode->nodeValue;
                        break;
                    case PropertyType::BOOLEAN:
                        $values[] = (bool)$valueNode->nodeValue;
                        break;
                    case PropertyType::LONG:
                        $values[] = (int)$valueNode->nodeValue;
                        break;
                    case PropertyType::BINARY:
                        $values[] = (int)$valueNode->nodeValue;
                        break;
                    case PropertyType::DATE:
                        $values[] = $valueNode->nodeValue;
                        break;
                    case PropertyType::DOUBLE:
                        $values[] = (double)$valueNode->nodeValue;
                        break;
                    default:
                        throw new \InvalidArgumentException("Type with constant " . $type . " not found.");
                }
            }

            if (PropertyType::BINARY == $type) {
                if (1 == $propertyNode->getAttribute('sv:multi-valued')) {
                    $data->{':' . $name} = $values;
                } else {
                    $data->{':' . $name} = $values[0];
                }
            } else {
                if (1 == $propertyNode->getAttribute('sv:multi-valued')) {
                    $data->{$name} = $values;
                } else {
                    $data->{$name} = $values[0];
                }
                $data->{':' . $name} = $type;
            }
        }

        // If the node is referenceable, return jcr:uuid.
        $is_referenceable = false;
        if (isset($data->{"jcr:mixinTypes"})) {
            foreach ((array) $data->{"jcr:mixinTypes"} as $mixin) {
                if ($this->nodeTypeManager->getNodeType($mixin)->isNodeType('mix:referenceable')) {
                    $is_referenceable = true;
                    break;
                }
            }
        }
        if ($is_referenceable) {
            $data->{'jcr:uuid'} = $row['identifier'];
        }

        return $data;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodes($paths)
    {
        foreach ($paths as $path) {
            $this->assertValidPath($path);
        }
        $this->assertLoggedIn();

        $query = 'SELECT path AS arraykey, id, path, parent, local_name, namespace, workspace_name, identifier, type, props, depth, sort_order
            FROM phpcr_nodes WHERE workspace_name = ? AND path IN (?)';
        $params = array($this->workspaceName, $paths);
        $stmt = $this->conn->executeQuery($query, $params, array(\PDO::PARAM_STR, Connection::PARAM_STR_ARRAY));
        $all = $stmt->fetchAll(\PDO::FETCH_UNIQUE | \PDO::FETCH_GROUP);

        $nodes = array();
        foreach ($paths as $key => $path) {
            if (isset($all[$path])) {
                $nodes[$key] = $this->getNodeData($path, $all[$path]);
            }
        }

        return $nodes;
    }

    private function pathExists($path)
    {
        $query = 'SELECT id FROM phpcr_nodes WHERE path = ? AND workspace_name = ?';
        if ($nodeId = $this->conn->fetchColumn($query, array($path, $this->workspaceName))) {
            return $nodeId;
        }

        return false;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteNode($path)
    {
        $this->assertLoggedIn();

        if ('/' == $path) {
            throw new ConstraintViolationException('You can not delete the root node of a repository');
        }

        $nodeId = $this->pathExists($path);

        if (!$nodeId) {
            throw new ItemNotFoundException("No node found at ".$path);
        }
        $params = array($path, $path."/%", $this->workspaceName);

        $query =
            'SELECT COUNT(*)
             FROM phpcr_nodes_foreignkeys fk
               INNER JOIN phpcr_nodes n ON n.id = fk.target_id
             WHERE (n.path = ? OR n.path LIKE ?)
               AND workspace_name = ?
               AND fk.type = ' . PropertyType::REFERENCE;
        $fkReferences = $this->conn->fetchColumn($query, $params);
        if ($fkReferences > 0) {
            /*
            TODO: if we had logging, we could report which nodes
            $query =
                'SELECT fk.source_id
                 FROM phpcr_nodes_foreignkeys fk
                   INNER JOIN phpcr_nodes n ON n.id = fk.target_id
                   INNER JOIN phpcr_nodes f ON f.id = fk.source_id
                 WHERE (n.path = ? OR n.path LIKE ?)
                   AND n.workspace_name = ?
                   AND fk.type = ' . PropertyType::REFERENCE;
            $paths = $this->conn->fetchAssoc($query, $params);
            */
            throw new ReferentialIntegrityException("Cannot delete $path: A reference points to this node or a subnode");
        }

        $query =
            'DELETE FROM phpcr_nodes
             WHERE (path = ? OR path LIKE ?)
               AND workspace_name = ?';

        $this->conn->beginTransaction();

        try {
            $this->conn->executeUpdate($query, $params);
            $this->conn->commit();
        } catch (\Exception $e) {
            $this->conn->rollBack();

            return false;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteProperty($path)
    {
        $this->assertLoggedIn();

        $nodePath = $this->getParentPath($path);
        $nodeId = $this->pathExists($nodePath);
        if (!$nodeId) {
            // no we really don't know that path
            throw new ItemNotFoundException("No item found at ".$path);
        }

        if ('/' == $nodePath) {
            // root node is a special case
            $propertyName = substr($path, 1);
        } else {
            $propertyName = str_replace($nodePath . '/', '', $path);
        }

        $query = 'SELECT props FROM phpcr_nodes WHERE id = ?';
        $xml = $this->conn->fetchColumn($query, array($nodeId));

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->loadXml($xml);

        $this->conn->beginTransaction();

        $found = false;
        foreach ($dom->getElementsByTagNameNS('http://www.jcp.org/jcr/sv/1.0', 'property') as $propertyNode) {
            if ($propertyName == $propertyNode->getAttribute('sv:name')) {
                $found = true;

                // would be nice to have the property object to ask for type
                // but its in state deleted, would mean lots of refactoring
                if ($propertyNode->hasAttribute('sv:type') &&
                    ('reference' == $propertyNode->getAttribute('sv:type')
                        || 'weakreference' == $propertyNode->getAttribute('sv:type')
                    )
                ) {
                    $query =
                        'DELETE FROM phpcr_nodes_foreignkeys
                         WHERE source_id = ?
                            AND source_property_name = ?';
                    try {
                        $this->conn->executeUpdate($query, array($nodeId, $propertyName));
                    } catch (\Exception $e) {
                        $this->conn->rollBack();

                        return false;
                    }
                }
                $propertyNode->parentNode->removeChild($propertyNode);
                break;
            }
        }
        if (! $found) {
            $this->conn->rollBack();
            throw new ItemNotFoundException("Node $nodePath has no property $propertyName");
        }
        $xml = $dom->saveXML();

        $query = 'UPDATE phpcr_nodes SET props = ? WHERE id = ?';
        $params = array($xml, $nodeId);

        try {
            $this->conn->executeUpdate($query, $params);
            $this->conn->commit();
        } catch (\Exception $e) {
            $this->conn->rollBack();

            return false;
        }

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function moveNode($srcAbsPath, $dstAbsPath)
    {
        $this->assertLoggedIn();

        $this->assertValidPath($dstAbsPath, true);

        $srcNodeId = $this->pathExists($srcAbsPath);
        if (!$srcNodeId) {
            throw new PathNotFoundException("Source path '$srcAbsPath' not found");
        }

        if ($this->pathExists($dstAbsPath)) {
            throw new ItemExistsException("Cannot move '$srcAbsPath' to '$dstAbsPath' because destination node already exists.");
        }

        if (!$this->pathExists($this->getParentPath($dstAbsPath))) {
            throw new PathNotFoundException("Parent of the destination path '" . $this->getParentPath($dstAbsPath) . "' has to exist.");
        }

        try {
            $this->conn->beginTransaction();

            $query = 'SELECT path, id FROM phpcr_nodes WHERE path LIKE ? OR path = ? AND workspace_name = ? ' . $this->conn->getDatabasePlatform()->getForUpdateSQL();
            $stmt = $this->conn->executeQuery($query, array($srcAbsPath . '/%', $srcAbsPath, $this->workspaceName));

            /*
             * TODO: https://github.com/jackalope/jackalope-doctrine-dbal/pull/26/files#L0R1057
             * the other thing i wonder: can't you do the replacement inside sql instead of loading and then storing
             * the node? this will be extremly slow for a large set of nodes. i think you should use query builder here
             * rather than raw sql, to make it work on a maximum of platforms.
             *
             * can you try to do this please? if we don't figure out how to do it, at least fix the where criteria, and
             * we can ask the doctrine community how to do the substring operation.
             * http://stackoverflow.com/questions/8619421/correct-syntax-for-doctrine2s-query-builder-substring-helper-method
             */

            $ids                 = '';
            $query               = "UPDATE phpcr_nodes SET ";
            $updatePathCase      = "path = CASE ";
            $updateParentCase    = "parent = CASE ";
            $updateLocalNameCase = "local_name = CASE ";
            $updateSortOrderCase = "sort_order = CASE ";
            $updateDepthCase     = "depth = CASE ";

            $i = 0;

            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {

                $values[':id' . $i]     = $row['id'];
                $values[':path' . $i]   = str_replace($srcAbsPath, $dstAbsPath, $row['path']);
                $values[':parent' . $i] = dirname($values[':path' . $i]);
                $values[':depth' . $i]  = substr_count($values[':path' . $i], "/");

                $updatePathCase   .= "WHEN id = :id" . $i . " THEN :path" . $i . " ";
                $updateParentCase .= "WHEN id = :id" . $i . " THEN :parent" . $i . " ";
                $updateDepthCase  .= "WHEN id = :id" . $i . " THEN :depth" . $i . " ";

                if ($srcAbsPath === $row['path']) {
                    $values[':localname' . $i] = basename($values[':path' . $i]);

                    $updateLocalNameCase .= "WHEN id = :id" . $i . " THEN :localname" . $i . " ";
                    $updateSortOrderCase .= "WHEN id = :id" . $i . " THEN (SELECT * FROM ( SELECT MAX(x.sort_order) + 1 FROM phpcr_nodes x WHERE x.parent = :parent" . $i . ") y) ";
                }

                $ids .= $row['id'] . ',';

                $i ++;
            }

            $ids = rtrim($ids, ',');

            $updateLocalNameCase .= "ELSE local_name END, ";
            $updateSortOrderCase .= "ELSE sort_order END ";

            $query .= $updatePathCase . "END, " . $updateParentCase . "END, " . $updateDepthCase . "END, " . $updateLocalNameCase . $updateSortOrderCase;
            $query .= "WHERE id IN (" . $ids . ")";

            $this->conn->executeUpdate($query, $values);
            $this->conn->commit();
        } catch (\Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function reorderNodes($absPath, $reorders)
    {
        $this->assertLoggedIn();

       /* Solution:
        * - Determine the current order (from DB query).
        * - Use the $reorders to calculate the new order.
        * - Compare the old and new sequences to generate the required update statements.
        * We cant just use the $reorders to get UPDATE statements directly as even a simple single move, from being the
        * last sibling to being the first, could result in the need to update the sort_order of every sibling.
        */

        // Retrieve an array of siblings names in the original order.
        $this->conn->beginTransaction();
        $qb = $this->conn->createQueryBuilder();

        $qb->select("CONCAT(n.namespace,(CASE namespace WHEN '' THEN '' ELSE ':' END), n.local_name)")
           ->from('phpcr_nodes', 'n')
           ->where('n.parent = :absPath')
           ->orderBy('n.sort_order', 'ASC');

        $query = $qb->getSql() . ' ' . $this->conn->getDatabasePlatform()->getForUpdateSQL();

        $stmnt = $this->conn->executeQuery($query, array('absPath' => $absPath));

        while ($row = $stmnt->fetchColumn()) {
            $original[] = $row;
        }

        // Flip to access via the name.
        $modified = array_flip($original);

        foreach ($reorders as $reorder) {
            if (null === $reorder[1]) {
                // Case: need to move node to the end of the array.
                // Remove from old position and append to end.
                unset($modified[$reorder[0]]);
                $modified = array_flip($modified);
                $modified[] = $reorder[0];

                // Resequence keys and flip back so we can access via name again.
                $modified = array_values($modified);
                $modified = array_flip($modified);
            } else {
                // Case: need to move node to before the specified target.
                // Remove from old position, resequence the keys and flip back so we can access by name again.
                unset($modified[$reorder[0]]);
                $modified = array_keys($modified);
                $modified = array_flip($modified);

                // Get target position and splice in.
                $targetPos = $modified[$reorder[1]];
                $modified = array_flip($modified);
                array_splice($modified, $targetPos, 0, $reorder[0]);
                $modified = array_flip($modified);
            }
        }

        try {
            $values[':absPath'] = $absPath;
            $sql = "UPDATE phpcr_nodes SET sort_order = CASE CONCAT(
              namespace,
              (CASE namespace WHEN '' THEN '' ELSE ':' END),
              local_name
            )";

            $i = 0;

            foreach ($modified as $name => $order) {
                $values[':name' . $i] = $name;
                $values[':order' . $i] = $order;
                $sql .= " WHEN :name" . $i . " THEN :order" . $i;
                $i++;
            }

            $sql .= " ELSE sort_order END WHERE parent = :absPath";

            $this->conn->executeUpdate($sql, $values);
            $this->conn->commit();
        } catch (\Exception $e) {
            $this->conn->rollBack();
            throw $e;
        }

    }

    /**
     * Get parent path of a path.
     *
     * @param string $path
     * @return string
     */
    private function getParentPath($path)
    {
        $parent = implode('/', array_slice(explode('/', $path), 0, -1));
        if (!$parent) {
            return '/';
        }

        return $parent;
    }

    /**
     * TODO: we should move that into the common Jackalope BaseTransport or as new method of NodeType
     * it will be helpful for other implementations.
     *
     * Validate this node with the nodetype and generate not yet existing
     * autogenerated properties as necessary.
     *
     * @param Node $node
     * @param NodeType $def
     */
    private function validateNode(Node $node, NodeType $def)
    {
        foreach ($def->getDeclaredChildNodeDefinitions() as $childDef) {
            /* @var $childDef \PHPCR\NodeType\NodeDefinitionInterface */
            if (!$node->hasNode($childDef->getName())) {
                if ('*' === $childDef->getName()) {
                    continue;
                }

                if ($childDef->isMandatory() && !$childDef->isAutoCreated()) {
                    throw new RepositoryException(
                        "Child " . $childDef->getName() . " is mandatory, but is not present while ".
                        "saving " . $def->getName() . " at " . $node->getPath()
                    );
                }

                if ($childDef->isAutoCreated()) {
                    throw new NotImplementedException("Auto-creation of child node '".$def->getName()."#".$childDef->getName()."' is not yet supported in DoctrineDBAL transport.");
                }
            }
        }

        foreach ($def->getDeclaredPropertyDefinitions() as $propertyDef) {
            /* @var $propertyDef \PHPCR\NodeType\PropertyDefinitionInterface */
            if ('*' == $propertyDef->getName()) {
                continue;
            }

            if (!$node->hasProperty($propertyDef->getName())) {
                if ($propertyDef->isMandatory() && !$propertyDef->isAutoCreated()) {
                    throw new RepositoryException(
                        "Property " . $propertyDef->getName() . " is mandatory, but is not present while ".
                        "saving " . $def->getName() . " at " . $node->getPath()
                    );
                }
                if ($propertyDef->isAutoCreated()) {
                    switch ($propertyDef->getName()) {
                        case 'jcr:uuid':
                            $value = UUIDHelper::generateUUID();
                            break;
                        case 'jcr:createdBy':
                        case 'jcr:lastModifiedBy':
                            $value = $this->credentials->getUserID();
                            break;
                        case 'jcr:created':
                        case 'jcr:lastModified':
                            $value = new \DateTime();
                            break;
                        case 'jcr:etag':
                            // TODO: http://www.day.com/specs/jcr/2.0/3_Repository_Model.html#3.7.12.1%20mix:etag
                            $value = 'TODO: generate from binary properties of this node';
                            break;

                        default:
                            $defaultValues = $propertyDef->getDefaultValues();
                            if ($propertyDef->isMultiple()) {
                                $value = $defaultValues;
                            } elseif (isset($defaultValues[0])) {
                                $value = $defaultValues[0];
                            } else {
                                // When implementing versionable or activity, we need to handle more properties explicitly
                                throw new RepositoryException('No default value for autocreated property '.
                                    $propertyDef->getName(). ' at '.$node->getPath());
                            }
                    }

                    $node->setProperty(
                        $propertyDef->getName(),
                        $value,
                        $propertyDef->getRequiredType()
                    );
                }
            }
        }

        foreach ($node->getProperties() as $property) {
            $this->assertValidProperty($property);
        }
    }

    private function getResponsibleNodeTypes(Node $node)
    {
        // This is very slow i believe :-(
        $nodeDef = $node->getPrimaryNodeType();
        $nodeTypes = $node->getMixinNodeTypes();
        array_unshift($nodeTypes, $nodeDef);

        return $nodeTypes;
    }

    /**
     * Recursively store a node and its children to the given absolute path.
     *
     * Transport stores the node at its path, with all properties and all
     * children.
     *
     * @param \Jackalope\Node $node the node to store
     * @param bool $saveChildren false to store only the current node and not its children
     *
     * @return bool true on success
     *
     * @throws \PHPCR\RepositoryException if not logged in
     */
    public function storeNode(Node $node, $saveChildren = true)
    {
        $this->assertLoggedIn();

        $nodeTypes = $this->getResponsibleNodeTypes($node);
        foreach ($nodeTypes as $nodeType) {
            /* @var $nodeType \PHPCR\NodeType\NodeTypeDefinitionInterface */
            $this->validateNode($node, $nodeType);
        }

        $properties = $node->getProperties();

        $path = $node->getPath();
        if (isset($this->nodeIdentifiers[$path])) {
            $nodeIdentifier = $this->nodeIdentifiers[$path];
        } elseif (isset($properties['jcr:uuid'])) {
            $nodeIdentifier = $properties['jcr:uuid']->getValue();
        } else {
            // we always generate a uuid, even for non-referenceable nodes that have no automatic uuid
            $nodeIdentifier = UUIDHelper::generateUUID();
        }
        $type = isset($properties['jcr:primaryType']) ? $properties['jcr:primaryType']->getValue() : "nt:unstructured";

        $this->syncNode($nodeIdentifier, $path, $this->getParentPath($path), $type, $node->isNew(), $node->getDepth(), $properties);

        if (!$saveChildren) {
            return true;
        }

        foreach ($node as $child) {
            /** @var $child Node */
            if ($child->isNew()) {
                // recursively call ourselves
                $this->storeNode($child);
            }
            // else this is an existing node moved to this location
        }
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function storeProperty(Property $property)
    {
        $this->assertLoggedIn();

        $node = $property->getParent();
        //do not store the children nodes, already taken into account previously with storeNode
        $this->storeNode($node, false);

        return true;
    }

    /**
     * Validation if all the data is correct before writing it into the database.
     *
     * @param \PHPCR\PropertyInterface $property
     *
     * @throws \PHPCR\ValueFormatException
     *
     * @return void
     */
    private function assertValidProperty($property)
    {
        $type = $property->getType();
        switch ($type) {
            case PropertyType::NAME:
                $values = $property->getValue();
                if (!$property->isMultiple()) {
                    $values = array($values);
                }
                foreach ($values as $value) {
                    $pos = strpos($value, ':');
                    if (false !== $pos) {
                        $prefix = substr($value, 0, $pos);

                        $this->getNamespaces();
                        if (!isset($this->namespaces[$prefix])) {
                            throw new ValueFormatException("Invalid PHPCR NAME at '" . $property->getPath() . "': The namespace prefix " . $prefix . " does not exist.");
                        }
                    }
                }
                break;
            case PropertyType::PATH:
                $values = $property->getValue();
                if (!$property->isMultiple()) {
                    $values = array($values);
                }
                foreach ($values as $value) {
                    if (!preg_match('(((/|..)?[-a-zA-Z0-9:_]+)+)', $value)) {
                        throw new ValueFormatException("Invalid PATH '$value' at '" . $property->getPath() ."': Segments are separated by / and allowed chars are -a-zA-Z0-9:_");
                    }
                }
                break;
            case PropertyType::URI:
                $values = $property->getValue();
                if (!$property->isMultiple()) {
                    $values = array($values);
                }
                foreach ($values as $value) {
                    if (!preg_match(self::VALIDATE_URI_RFC3986, $value)) {
                        throw new ValueFormatException("Invalid URI '$value' at '" . $property->getPath() ."': Has to follow RFC 3986.");
                    }
                }
                break;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getNodePathForIdentifier($uuid)
    {
        $this->assertLoggedIn();

        $path = $this->conn->fetchColumn("SELECT path FROM phpcr_nodes WHERE identifier = ? AND workspace_name = ?", array($uuid, $this->workspaceName));
        if (!$path) {
            throw new ItemNotFoundException("no item found with uuid ".$uuid);
        }

        return $path;
    }

    /**
     * {@inheritDoc}
     */
    public function getNodeTypes($nodeTypes = array())
    {
        $standardTypes = array();
        foreach (StandardNodeTypes::getNodeTypeData() as $nodeTypeData) {
            $standardTypes[$nodeTypeData['name']] = $nodeTypeData;
        }

        $userTypes = $this->fetchUserNodeTypes();

        if ($nodeTypes) {
            $nodeTypes = array_flip($nodeTypes);
            // TODO: check if user types can override standard types.
            return array_values(array_intersect_key($standardTypes, $nodeTypes) + array_intersect_key($userTypes, $nodeTypes));
        }

        return array_values($standardTypes + $userTypes);
    }

    /**
     * Fetch a user-defined node-type definition.
     *
     * @param string $name
     * @return array
     */
    protected function fetchUserNodeTypes()
    {
        $result = array();
        $query = "SELECT * FROM phpcr_type_nodes";
        foreach ($this->conn->fetchAll($query) as $data) {
            $name = $data['name'];
            $result[$name] = array(
                'name' => $name,
                'isAbstract' => (bool)$data['is_abstract'],
                'isMixin' => (bool)($data['is_mixin']),
                'isQueryable' => (bool)$data['queryable'],
                'hasOrderableChildNodes' => (bool)$data['orderable_child_nodes'],
                'primaryItemName' => $data['primary_item'],
                'declaredSuperTypeNames' => array_filter(explode(' ', $data['supertypes'])),
                'declaredPropertyDefinitions' => array(),
                'declaredNodeDefinitions' => array(),
            );

            $query = 'SELECT * FROM phpcr_type_props WHERE node_type_id = ?';
            $props = $this->conn->fetchAll($query, array($data['node_type_id']));
            foreach ($props as $propertyData) {
                $result[$name]['declaredPropertyDefinitions'][] = array(
                    'declaringNodeType' => $data['name'],
                    'name' => $propertyData['name'],
                    'isAutoCreated' => (bool)$propertyData['auto_created'],
                    'isMandatory' => (bool)$propertyData['mandatory'],
                    'isProtected' => (bool)$propertyData['protected'],
                    'onParentVersion' => $propertyData['on_parent_version'],
                    'requiredType' => $propertyData['required_type'],
                    'multiple' => (bool)$propertyData['multiple'],
                    'isFulltextSearchable' => (bool)$propertyData['fulltext_searchable'],
                    'isQueryOrderable' => (bool)$propertyData['query_orderable'],
                    'queryOperators' => array (
                        0 => 'jcr.operator.equal.to',
                        1 => 'jcr.operator.not.equal.to',
                        2 => 'jcr.operator.greater.than',
                        3 => 'jcr.operator.greater.than.or.equal.to',
                        4 => 'jcr.operator.less.than',
                        5 => 'jcr.operator.less.than.or.equal.to',
                        6 => 'jcr.operator.like',
                    ),
                    'defaultValue' => array($propertyData['default_value']),
                );
            }

            $query = 'SELECT * FROM phpcr_type_childs WHERE node_type_id = ?';
            $childs = $this->conn->fetchAll($query, array($data['node_type_id']));
            foreach ($childs as $childData) {
                $result[$name]['declaredNodeDefinitions'][] = array(
                    'declaringNodeType' => $data['name'],
                    'name' => $childData['name'],
                    'isAutoCreated' => (bool)$childData['auto_created'],
                    'isMandatory' => (bool)$childData['mandatory'],
                    'isProtected' => (bool)$childData['protected'],
                    'onParentVersion' => $childData['on_parent_version'],
                    'allowsSameNameSiblings' => false,
                    'defaultPrimaryTypeName' => $childData['default_type'],
                    'requiredPrimaryTypeNames' => array_filter(explode(" ", $childData['primary_types'])),
                );
            }
        }

        return $result;
    }

    /**
     * {@inheritDoc}
     */
    public function registerNodeTypesCnd($cnd, $allowUpdate)
    {
        throw new NotImplementedException('Not implemented yet');
    }

    /**
     * {@inheritDoc}
     */
    public function registerNodeTypes($types, $allowUpdate)
    {
        foreach ($types as $type) {
            /* @var $type \Jackalope\NodeType\NodeTypeDefinition */
            $this->conn->insert('phpcr_type_nodes', array(
                'name' => $type->getName(),
                'supertypes' => implode(' ', $type->getDeclaredSuperTypeNames()),
                'is_abstract' => $type->isAbstract() ? 1 : 0,
                'is_mixin' => $type->isMixin() ? 1 : 0,
                'queryable' => $type->isQueryable() ? 1 : 0,
                'orderable_child_nodes' => $type->hasOrderableChildNodes() ? 1 : 0,
                'primary_item' => $type->getPrimaryItemName(),
            ));
            $nodeTypeId = $this->conn->lastInsertId($this->sequenceTypeName);

            if ($propDefs = $type->getDeclaredPropertyDefinitions()) {
                foreach ($propDefs as $propertyDef) {
                    /* @var $propertyDef \Jackalope\NodeType\PropertyDefinition */
                    $this->conn->insert('phpcr_type_props', array(
                        'node_type_id' => $nodeTypeId,
                        'name' => $propertyDef->getName(),
                        'protected' => $propertyDef->isProtected() ? 1 : 0,
                        'mandatory' => $propertyDef->isMandatory() ? 1 : 0,
                        'auto_created' => $propertyDef->isAutoCreated() ? 1 : 0,
                        'on_parent_version' => $propertyDef->getOnParentVersion(),
                        'multiple' => $propertyDef->isMultiple() ? 1 : 0,
                        'fulltext_searchable' => $propertyDef->isFullTextSearchable() ? 1 : 0,
                        'query_orderable' => $propertyDef->isQueryOrderable() ? 1 : 0,
                        'required_type' => $propertyDef->getRequiredType(),
                        'query_operators' => 0, // transform to bitmask
                        'default_value' => $propertyDef->getDefaultValues() ? current($propertyDef->getDefaultValues()) : null,
                    ));
                }
            }

            if ($childDefs = $type->getDeclaredChildNodeDefinitions()) {
                foreach ($childDefs as $childDef) {
                    /* @var $propertyDef \PHPCR\NodeType\NodeDefinitionInterface */
                    $this->conn->insert('phpcr_type_childs', array(
                        'node_type_id' => $nodeTypeId,
                        'name' => $childDef->getName(),
                        'protected' => $childDef->isProtected() ? 1 : 0,
                        'mandatory' => $childDef->isMandatory() ? 1 : 0,
                        'auto_created' => $childDef->isAutoCreated() ? 1 : 0,
                        'on_parent_version' => $childDef->getOnParentVersion(),
                        'primary_types' => implode(' ', $childDef->getRequiredPrimaryTypeNames() ?: array()),
                        'default_type' => $childDef->getDefaultPrimaryTypeName(),
                    ));
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function setNodeTypeManager($nodeTypeManager)
    {
        $this->nodeTypeManager = $nodeTypeManager;
    }

    /**
     * {@inheritDoc}
     */
    public function cloneFrom($srcWorkspace, $srcAbsPath, $destAbsPath, $removeExisting)
    {
        throw new NotImplementedException('Cloning nodes is not implemented yet');
    }

    /**
     * {@inheritDoc}
     */
    public function getBinaryStream($path)
    {
        $this->assertLoggedIn();

        $nodePath = $this->getParentPath($path);
        $propertyName = ltrim(str_replace($nodePath, '', $path), '/'); // i dont know why trim here :/
        $nodeId = $this->pathExists($nodePath);

        $data = $this->conn->fetchAll(
            'SELECT data, idx FROM phpcr_binarydata WHERE node_id = ? AND property_name = ? AND workspace_name = ?',
            array($nodeId, $propertyName, $this->workspaceName)
        );

        $streams = array();
        foreach ($data as $row) {
            if (is_resource($row['data'])) {
                $stream = $row['data'];
            } else {
                $stream = fopen('php://memory', 'rwb+');
                fwrite($stream, $row['data']);
                rewind($stream);
            }

            $streams[] = $stream;
        }

        // TODO even a multi value field could have only one value stored
        // we need to also fetch if the property is multi valued instead of this count() check
        if (count($data) > 1) {
            return $streams;
        }

        return reset($streams);
    }

    /**
     * {@inheritDoc}
     */
    public function getProperty($path)
    {
        throw new NotImplementedException('Getting properties by path is implemented yet');
    }

    /**
     * {@inheritDoc}
     */
    public function query(Query $query)
    {
        $this->assertLoggedIn();

        $limit = $query->getLimit();
        $offset = $query->getOffset();

        if (null !== $offset && null == $limit &&
            ($this->conn->getDatabasePlatform() instanceof MySqlPlatform
            || $this->conn->getDatabasePlatform() instanceof SqlitePlatform)
        ) {
            $limit = PHP_INT_MAX;
        }

        if (!$query instanceof QueryObjectModelInterface) {
            $parser = new Sql2ToQomQueryConverter($this->factory->get('Query\QOM\QueryObjectModelFactory'));
            try {
                $query = $parser->parse($query->getStatement());
            } catch (\Exception $e) {
                throw new InvalidQueryException('Invalid query: '.$query->getStatement());
            }
        }

        $source   = $query->getSource();

        if (!($source instanceof SelectorInterface)) {
            throw new NotImplementedException("Only Selector Sources are supported for now, but no Join.");
        }

        // TODO: this check is only relevant for Selector, not for Join. should we push it into the walker?
        $nodeType = $source->getNodeTypeName();

        if (!$this->nodeTypeManager->hasNodeType($nodeType)) {
            $msg = 'Selected node type does not exist: ' . $nodeType;
            if ($alias = $source->getSelectorName()) {
                $msg .= ' AS ' . $alias;
            }

            throw new InvalidQueryException($msg);
        }

        $qomWalker = new QOMWalker($this->nodeTypeManager, $this->conn, $this->getNamespaces());
        $sql = $qomWalker->walkQOMQuery($query);

        $sql = $this->conn->getDatabasePlatform()->modifyLimitQuery($sql, $limit, $offset);

        $data = $this->conn->fetchAll($sql, array($this->workspaceName));

        // The list of columns is required to filter each records props
        $columns = array();
        /** @var $column \PHPCR\Query\QOM\ColumnInterface */
        foreach ($query->getColumns() as $column) {
            $columns[$column->getPropertyName()] = $column->getSelectorName();
        }

        // TODO: this needs update once we implement join

        $selector = $source->getSelectorName();
        if (null === $selector) {
            $selector = $source->getNodeTypeName();
        }

        if (empty($columns)) {
            $columns = array(
                'jcr:createdBy'   => $selector,
                'jcr:created'     => $selector,
            );
        }

        $columns['jcr:primaryType'] = $selector;

        $results = array();
        // This block feels really clunky - maybe this should be a QueryResultFormatter class?
        foreach ($data as $row) {
            $result = array(
                array('dcr:name' => 'jcr:path', 'dcr:value' => $row['path'], 'dcr:selectorName' => $row['type']),
                array('dcr:name' => 'jcr:score', 'dcr:value' => 0, 'dcr:selectorName' => $row['type'])
            );

            // extract only the properties that have been requested in the query
            $props = static::xmlToProps($row['props'], function ($name) use ($columns) {
                return array_key_exists($name, $columns);
            });

            foreach ($columns AS $columnName => $columnPrefix) {
                $result[] = array(
                    'dcr:name' => null === $columnPrefix ? $columnName : "{$columnPrefix}.{$columnName}",
                    'dcr:value' => array_key_exists($columnName, $props) ? $props[$columnName] : null,
                    'dcr:selectorName' => $columnPrefix ?: $selector,
                );
            }

            $results[] = $result;
        }

        return $results;
    }

    /**
     * {@inheritDoc}
     */
    public function registerNamespace($prefix, $uri)
    {
        if (isset($this->namespaces[$prefix]) && $this->namespaces[$prefix] === $uri) {
            return;
        }

        $this->conn->beginTransaction();

        try {
            $this->conn->delete('phpcr_namespaces', array('prefix' => $prefix));
            $this->conn->delete('phpcr_namespaces', array('uri' => $uri));

            $this->conn->insert('phpcr_namespaces', array(
                'prefix' => $prefix,
                'uri' => $uri,
            ));

            $this->conn->commit();
        } catch (\Exception $e) {
            $this->conn->rollback();

            throw $e;
        }

        if (!empty($this->namespaces)) {
            $this->namespaces[$prefix] = $uri;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function unregisterNamespace($prefix)
    {
        $this->conn->delete('phpcr_namespaces', array('prefix' => $prefix));

        if (!empty($this->namespaces)) {
            unset($this->namespaces[$prefix]);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getReferences($path, $name = null)
    {
        return $this->getNodeReferences($path, $name, false);
    }

    /**
     * {@inheritDoc}
     */
    public function getWeakReferences($path, $name = null)
    {
        return $this->getNodeReferences($path, $name, true);
    }

    /**
     * @param string $path the path for which we need the references
     * @param string $name the name of the referencing properties or null for all
     * @param bool $weak_reference whether to get weak or strong references
     *
     * @return array list of paths to nodes that reference $path
     */
    private function getNodeReferences($path, $name = null, $weakReference = false)
    {
        $targetId = $this->pathExists($path);

        $type = $weakReference ? PropertyType::WEAKREFERENCE : PropertyType::REFERENCE;

        $query = "SELECT CONCAT(n.path, '/', fk.source_property_name) as path, fk.source_property_name FROM phpcr_nodes n".
            '   INNER JOIN phpcr_nodes_foreignkeys fk ON n.id = fk.source_id'.
            '   WHERE fk.target_id = ? AND fk.type = ?';
        $properties = $this->conn->fetchAll($query, array($targetId, $type));

        $references = array();
        foreach ($properties as $property) {
            if (null === $name || $property['source_property_name'] == $name) {
                $references[] = $property['path'];
            }
        }
        return $references;
    }

    /**
     * {@inheritDoc}
     */
    public function beginTransaction()
    {
        if ($this->inTransaction) {
            throw new RepositoryException('Begin transaction failed: transaction already open');
        }

        $this->assertLoggedIn();

        try {
            $this->conn->beginTransaction();
            $this->inTransaction = true;
        } catch (\Exception $e) {
            throw new RepositoryException('Begin transaction failed: '.$e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function commitTransaction()
    {
        if (!$this->inTransaction) {
            throw new RepositoryException('Commit transaction failed: no transaction open');
        }

        $this->assertLoggedIn();

        try {
            $this->inTransaction = false;

            $this->conn->commit();
        } catch (\Exception $e) {
            throw new RepositoryException('Commit transaction failed: ' . $e->getMessage());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function rollbackTransaction()
    {
        if (!$this->inTransaction) {
            throw new RepositoryException('Rollback transaction failed: no transaction open');
        }

        $this->assertLoggedIn();

        try {
            $this->inTransaction = false;
            $this->namespaces = array();

            $this->conn->rollback();
        } catch (\Exception $e) {
            throw new RepositoryException('Rollback transaction failed: ' . $e->getMessage());
        }
    }

    /**
     * Sets the default transaction timeout
     *
     * @param int $seconds The value of the timeout in seconds
     */
    public function setTransactionTimeout($seconds)
    {
        $this->assertLoggedIn();

        throw new NotImplementedException("Setting a transaction timeout is not yet implemented");
    }

    /**
     * {@inheritDoc}
     */
    public function finishSave()
    {
    }
}
