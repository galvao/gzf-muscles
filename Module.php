<?php
namespace GZFMuscles;

use Zend\Mvc\ModuleRouteListener;
use Zend\Mvc\MvcEvent;
use Zend\Db\ResultSet\ResultSet;
use Zend\Db\TableGateway\TableGateway;

class Module
{
    public static $modelNamespace = '\Application\Model\\';
    public static $entities = [];

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }

    public function setEntities(array $entities)
    {
        self::$entities = $entities;
    }

    public function getEntities()
    {
        return self::$entities;
    }

    public function getEntity($entity)
    {
        return $this->getServiceConfig($entity);
    }

    public function getServiceConfig()
    {
        $serviceConfig = array('factories' => array());

        foreach(self::$entities as $entity) {
            $serviceConfig['factories'][$entity] = function ($sm, $x, $entity) {
                $gateway  = $entity . 'Gateway';
                $class    = str_replace('Table', '', $entity);
                $dbEntity = strtolower(preg_replace('/([a-z])([A-Z]{1})/', '$1_$2', $entity));

                $tableGateway = $sm->get($gateway);
                $tableClass = self::$modelNamespace . $entity;
                $modelClass = self::$modelNamespace . $class;
                $table = new $tableClass($tableGateway);
                $table->model = new $modelClass();

                return $table;
            };

            $serviceConfig['factories'][$entity . 'Gateway'] = function ($sm, $e, $entity) {
                $class    = str_replace('TableGateway', '', $entity);
                $dbEntity = strtolower(preg_replace('/([a-z])([A-Z]{1})/', '$1_$2', $class));

                $modelClass = self::$modelNamespace . $class;
                $dbAdapter  = $sm->get('Zend\Db\Adapter\Adapter');
                $resultSetPrototype = new ResultSet();
                $resultSetPrototype->setArrayObjectPrototype(new $modelClass());
                return new TableGateway($dbEntity, $dbAdapter, null, $resultSetPrototype);
            };
        }

        return $serviceConfig;
    }
}
