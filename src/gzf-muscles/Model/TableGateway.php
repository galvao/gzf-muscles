<?php
namespace GZFMuscles\Model;

use Zend\Db\Sql\Sql;
use Zend\Db\Sql\Select;
use Zend\Db\ResultSet\ResultSet;
use Zend\Db\TableGateway\TableGateway as ZFTableGateway;
use Zend\Stdlib\Hydrator\ArraySerializable as Hydrator;
use Zend\Paginator\Paginator;
use Zend\Paginator\Adapter\DbSelect;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\ServiceManager\ServiceLocatorAwareInterface;

/**
 * TableGateway generalization
 *
 * One of the issues it solves is the fact that the action of inserting or updating a record isn't simple and it's
 * surely not easily decided by the presence or absence of one or more data points.
 *
 * Consider the following examples:
 *
 * ['id'     => null, 'name'   => 'foo'] // Regular insert
 * ['id'     => 1002, 'name'   => 'foo'] // Regular update
 * ['foo_id' => 1001, 'bar_id' =>  1002] // M:N insert or update, forceInsert is relevant
 * ['foo_id' => null, 'name'   => 'bar'] // Regular insert
 * ['foo_id' => 1002, 'name'   => 'baz'] // Regular insert or update, forceInsert is relevant
 *
 * @package GZFMuscles
 */

class TableGateway extends ZFTableGateway implements ServiceLocatorAwareInterface
{
    public $dbAdapter;
    public $hydrator;
    public $model;
    protected $serviceLocator;
    protected $relatedEntities = array('parent' => array(),
        'child' => array()
    );
    protected $identifiers = array();

    /**
     * Getters and Setters
     */

    public function setServiceLocator(ServiceLocatorInterface $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
    }

    public function getServiceLocator()
    {
        return $this->serviceLocator;
    }

    public function setAdapter()
    {
        $this->dbAdapter = $this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');
    }

    public function getAdapter()
    {
        if (!$this->dbAdapter) {
            $this->setAdapter();
        }

        return $this->dbAdapter;
    }

    public function setHydrator()
    {
        $this->hydrator = new Hydrator();
    }

    public function getHydrator()
    {
        if (!$this->hydrator) {
            $this->setHydrator();
        }

        return $this->hydrator;
    }

    /**
     * Adds another TableGateway as a parent entity.
     *
     * @param GZFMuscles\Model\TableGateway $entity
     * @param string $seekColumn
     */

    public function addParentEntity(\GZFMuscles\Model\TableGateway $entity, $seekColumn)
    {
        if (!$this->isParent($entity)) {
            $this->relatedEntities['parent'][] = array('entity' => $entity,
                'column' => $seekColumn
            );
        }
    }

    /**
     * Adds another TableGateway as a child entity.
     *
     * @param \GZFMuscles\Model\TableGateway $entity
     * @param string $sortColumn
     */

    public function addChildEntity(\GZFMuscles\Model\TableGateway $entity, $sortColumn)
    {
        if (!$this->isChild($entity)) {
            $this->relatedEntities['child'][] = array('entity' => $entity,
                'column' => $sortColumn
            );
        }
    }

    public function isParent(\GZFMuscles\Model\TableGateway $entity)
    {
        return in_array($entity, $this->relatedEntities['parent']);
    }

    public function isChild(\GZFMuscles\Model\TableGateway $entity)
    {
        return in_array($entity, $this->relatedEntities['child']);
    }

    public function getChildEntities()
    {
        return $this->relatedEntities['child'];
    }

    public function getParentEntities()
    {
        return $this->relatedEntities['parent'];
    }

    public function getRelatedEntities()
    {
        return $this->relatedEntities;
    }

    /**
     * Adds an identifier to the entity
     *
     * @param string $key - The name of the column that will be defined as one of the entity's identifiers
     */

    public function addIdentifier($key)
    {
        if (!isset($this->identifiers[$key])) {
            $this->identifiers[$key] = null;
        }
    }

    /**
     * Sets the value for a previously defined identifier
     *
     * @param string $key   - The name of the column that will be defined as one of the entity's identifiers
     * @param mixed  $value - The value to be defined
     */

    public function setIdentifier($key, $value)
    {
        if (array_key_exists($key, $this->identifiers)) {
            $this->identifiers[$key] = (int)$value;
        }
    }

    /**
     * Gets the value for a previously defined identifier
     *
     * @param string $key - The name of the column that will be defined as one of the entity's identifiers
     *
     * @throws \Exception - Exception in case the identifier has not been previously defined
     */

    public function getIdentifier($key)
    {
        if (!isset($this->identifiers[$key])) {
            throw new \Exception("$key hasn't been defined as an identifier for entity " . $this->tableName . ".");
        }

        return $this->identifiers[$key];
    }

    public function getIdentifiers()
    {
        return $this->identifiers;
    }

    public function fetch(
        $clause = null,
        $sort = true,
        $sortColumn = 'name',
        $sortOrder = 'ASC',
        $paginate = true,
        $pageNumber = 1,
        $itemsPerPage = 20
    ) {
        $this->getAdapter();
        $sql    = new Sql($this->dbAdapter);
        $select = new Select();

        $select->from($this->tableGateway->table);

        /**
        * The following works because of the convention adopted for database modeling.
        * @see: https://github.com/galvao/dev-practices
        */

        foreach ($this->relatedEntities['parent'] as $parentEntity) {
            $otherParentGateway = $parentEntity['entity']->tableGateway;
            $otherParentColumn =  $otherParentGateway->table . '_id';
            $relatedParentClause = $otherParentColumn . ' = id';

            $select->join(array($otherParentGateway->table => $otherParentGateway->table),
                $relatedParentClause
            );
        }

        foreach ($this->relatedEntities['child'] as $childEntity) {
            $childGateway  = $childEntity['entity']->tableGateway;
            $foreignColumn = $childGateway->table . '_id';
            $relatedClause = $foreignColumn . ' = id';

            $select->join(array($childGateway->table => $childGateway->table),
                $relatedClause
            );
        }

        if ($clause) {
            $select->where($clause);
        }

        if ($sort) {
            $select->order(array($sortColumn => $sortOrder));
        }

        $statement = $sql->prepareStatementForSqlObject($select);
        $result = $statement->execute();

        $resultSet = new ResultSet();
        $resultSet->initialize($result);

        if ($paginate) {
            $paginatorAdapter = new DbSelect(
                $select,
                $this->tableGateway->getAdapter(),
                $resultSet
            );

            $result = new Paginator($paginatorAdapter);
            $result->setCurrentPageNumber($pageNumber);
            $result->setItemCountPerPage($itemsPerPage);
        }

        return $result;
    }

    public function search($cols, $clause)
    {
        $this->getAdapter();

        $select = new Select();
        $select->from($this->tableGateway->table);

        if (!isset($cols[0])) {
            $select->columns($cols, false);
        }

        $select->where($clause);

        $result = $this->dbAdapter->query(str_replace('"', '`', @$select->getSqlString()),
        \Zend\Db\Adapter\Adapter::QUERY_MODE_EXECUTE);

        $result->buffer();

        if (!$result->count()) {
            return false;
        }

        return $result->current();
    }

    public function find($asArray = false)
    {
        $rowset = $this->tableGateway->select($this->identifiers);
        $row = $rowset->current();

        if (!$row) {
            throw new \Exception('Record for ' . $this->tableName . ' not found.');
        }

        if ($this->relatedEntities) {
            foreach ($this->relatedEntities['parent'] as $parentEntity) {
                /**
                 * The following works because of the convention adopted for database modeling.
                 * @see: https://github.com/galvao/dev-practices
                 */

                $parentGateway = $parentEntity['entity']->tableGateway;

                $foreignKey     = $parentGateway->table . '_id';
                $parentDataName = $parentGateway->table . '_name';
                $foreignID      = $row->$foreignKey;

                /**
                 * This has to be checked. This only works now because M:N entites are always child entities, but this
                 * WILL probably break, since any entity with multiple PKs that has children won't accept this nicely.
                 * Also, I need to check variable naming on both loops. These names look a bit off.
                 */
                foreach ($parentEntity['entity']->getIdentifiers() as $parentCol => $parentValue) {
                    $parentEntity['entity']->setIdentifier($parentCol, $foreignID);
                }

                $foreignData = $parentEntity['entity']->find(true);

                $row->$parentDataName = $foreignData[$parentEntity['column']];
            }

            foreach ($this->relatedEntities['child'] as $childEntity) {
                $childGateway   = $childEntity['entity']->tableGateway;
                $migratedColumn = $this->tableGateway->table . '_id';
                $fakeColumn     = $this->tableGateway->table . '_name';
                $migratedID     = $row->id;

                foreach ($childEntity['entity']->getIdentifiers() as $childCol => $childValue) {
                    $childEntity['entity']->setIdentifier($childCol, $migratedID);
                }

                /**
                 * No order for now. We need to establish a way of setting an ordering column for each
                 * related entity.
                 */

                //$row->related = $relatedEntity->fetch(array($migratedColumn => $migratedID),
                //    false,
                //    'number',
                //    'ASC'
                //);
                $row->related[$childGateway->table] = $childEntity['entity']->fetch(
                    array($migratedColumn => $migratedID),
                    false,
                    null,
                    null,
                    true,
                    1,
                    20
                );
            }
        }

        if ($asArray) {
            $this->getHydrator();
            $row = $this->hydrator->extract($row);
        }

        return $row;
    }

    /**
     * Inserts or updates data
     *
     * @param array|object $data   - Data to be saved
     * @param boolean $forceInsert - Force insertion. For M:N entities and entities where the PK is not called 'ID'
     * @throws \Exception
     * @return boolean|number
     */

    public function save($data, $forceInsert = true)
    {
        $this->getHydrator();

        /**
         * Binding a model to a form makes the getData method return an object and therefore the following code
         * becomes necessary.
         * When inserting, OTOH, since there's no binding, the data comes as an array.
         *
         * @todo: Try to standardize this. Either receive data as an array or as an object.
         * @todo: This method should always return the last inserted id, but how is this done when dealing with a
         * M:N entity? Array? Not really... in that case we already know the IDs involved... duh. Still, insert()
         * must return something... right?
         *
         * Do I really need to hydrate and extract if I've got an array? It's an interesting question...
         * On one hand the process ensures data "integrity". OTOTH it seems rather silly.
         */

        if (is_array($data)) {
            $this->hydrator->hydrate($data, $this->model);
            $data = $this->hydrator->extract($this->model);
        } else {
            $data = $this->hydrator->extract($data);
        }

        /**
         * @todo: Returned 'id' for cases with composite PKs
         */

        if ($forceInsert) {
            $this->tableGateway->insert($data);

            /**
             * @todo: setIdentifier instead?
             */

            $id = $this->tableGateway->getLastInsertValue();
        } else {
            try {
                $this->find();
            } catch(\Exception $e) {
                return false;
            }

            /**
             * @todo: One idea that I really like is to only really  update if any data has changed. It will be 
             * interesting to code this...
             */

            $this->tableGateway->update($data, $this->identifiers);
        }

        return $id;
    }

    public function erase()
    {
        try {
            $this->find();
        } catch(\Exception $e) {
            die($e->getMessage());
            return false;
        }

        try {
            $this->tableGateway->delete($this->identifiers);
        } catch (\PDOException $e) {
            return false;
        }

        return true;
    }
}
