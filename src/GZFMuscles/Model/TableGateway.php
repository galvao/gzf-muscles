<?php
namespace GZFMuscles\Model;

use Zend\Db\Sql\Sql;
use Zend\Db\Sql\Select;
use Zend\Db\ResultSet\ResultSet;
use Zend\Db\TableGateway\TableGateway as ZFTableGateway;
use Zend\Paginator\Paginator;
use Zend\Paginator\Adapter\DbSelect;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\ServiceManager\ServiceLocatorAwareInterface;

class TableGateway extends ZFTableGateway implements ServiceLocatorAwareInterface
{
    public $dbAdapter;
    public $hydrator;
    public $model;
    protected $serviceLocator;
    protected $relatedEntities = ['parent' => [], 'child' => []];
    protected $identifiers = [];

    /**
     * Getters and Setters
     */

    public function setServiceLocator(ServiceLocatorInterface $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
    }

    public function getServiceLocator()
    {
        if (!$this->serviceLocator) {
            $this->setServiceLocator();
        }

        return $this->serviceLocator;
    }

    public function setDbAdapter($key = 'Zend\Db\Adapter\Adapter')
    {
        $this->dbAdapter = $this->getServiceLocator()->get($key);
    }

    public function getDbAdapter()
    {
        if (!$this->dbAdapter) {
            $this->setDbAdapter();
        }

        return $this->dbAdapter;
    }

    public function setHydrator()
    {
        $config = $this->getServiceLocator()->get('config');
        $this->hydrator = new $config['Hydrator'];
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
            $this->identifiers[$key] = $value;
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
        $this->getDbAdapter();
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
     * @return boolean|number
     */

    public function create(Model $model)
    {
        $this->tableGateway->insert($this->getHydrator()->extract($model));
        return $this->tableGateway->getLastInsertValue();
    }

    public function modify(Model $model, $diff = TRUE)
    {
        try {
            $this->find();
        } catch(\Exception $e) {
            return false;
        }

        /*
         * @todo: One idea that I really like is to only really  update if any data has changed. It will be 
         * interesting to code this...
         */

        if ($diff) {
        }

        $this->tableGateway->update($data, $this->identifiers);
    }

    public function destroy()
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
