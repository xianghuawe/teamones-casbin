<?php

namespace teamones\casbin;

use Casbin\Model\Model;
use Casbin\Persist\Watcher;
use InvalidArgumentException;
use Casbin\Enforcer as BaseEnforcer;
use Casbin\Exceptions\CasbinException;
use teamones\casbin\adapters\DatabaseAdapter;

/**
 * Enforcer
 */
class Enforcer extends BaseEnforcer
{

    /**
     * @var null
     */
    protected static $_instance = null;

    /**
     * @param string $type
     * @return BaseEnforcer|null
     * @throws CasbinException
     */
    public static function instance(string $type = 'default')
    {
        if (empty(self::$_instance)) {

            $config = config('casbin', []);
            if (!isset($config[$type])) {
                throw new \RuntimeException("Casbin {$type} config not found.");
            }

            // 加载casbin model 配置
            $model = new Model();
            $configType = $config[$type]['model']['config_type'];
            if ('file' == $configType) {
                $model->loadModel($config[$type]['model']['config_file_path']);
            } elseif ('text' == $configType) {
                $model->loadModelFromText($config[$type]['model']['config_text']);
            }

            // 实例化casbin adapter 适配器
            if (empty($config[$type]['adapter']) && empty($config[$type]['adapter']['type']) && empty($config[$type]['adapter']['class']) && !class_exists($config[$type]['adapter']['class'])) {
                throw new InvalidArgumentException("Enforcer adapter is not defined.");
            }

            switch ($config[$type]['adapter']['type']) {
                case 'model':
                    // 使用支持 think-orm 的适配器
                    $ruleModel = new $config[$type]['adapter']['class']();
                    $adapter = new DatabaseAdapter($ruleModel);
                    break;
                case 'adapter':
                    // 使用自定义适配器
                    $adapter = new $config[$type]['adapter']['class']();
                    break;
                default:
                    throw new InvalidArgumentException("Only model and adapter are supported.");
                    break;
            }

            self::$_instance = new self($model, $adapter, false);
        }
        return self::$_instance;
    }

    /**
     * 获得watcher
     * @return Watcher|null
     */
    public function getWatcher()
    {
        return $this->watcher;
    }

    /**
     * @param $name
     * @param $arguments
     * @return mixed
     * @throws CasbinException
     */
    public static function __callStatic($name, $arguments)
    {
        return static::instance('default')->{$name}(... $arguments);
    }

}
