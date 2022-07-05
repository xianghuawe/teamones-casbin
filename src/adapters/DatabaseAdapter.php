<?php

declare(strict_types=1);

namespace teamones\casbin\adapters;

use think\facade\Db;
use Casbin\Model\Model;
use Casbin\Persist\Adapter;
use Casbin\Persist\BatchAdapter;
use Casbin\Persist\AdapterHelper;
use think\db\exception\DbException;
use Casbin\Persist\UpdatableAdapter;
use think\db\exception\DataNotFoundException;
use think\db\exception\ModelNotFoundException;

/**
 * DatabaseAdapter.
 */
class DatabaseAdapter implements Adapter, UpdatableAdapter, BatchAdapter
{

    use AdapterHelper;

    /**
     * @var \think\Model
     */
    protected \think\Model $eloquent;

    /**
     * DatabaseAdapter constructor.
     * @param $ruleModel
     */
    public function __construct($ruleModel)
    {
        $this->eloquent = $ruleModel;
    }

    /**
     * savePolicyLine function.
     *
     * @param string $ptype
     * @param array $rule
     */
    public function savePolicyLine(string $ptype, array $rule): void
    {
        $col['ptype'] = $ptype;
        foreach ($rule as $key => $value) {
            $col['v' . strval($key)] = $value;
        }

        $this->eloquent->create($col);
    }

    /**
     * loads all policy rules from the storage.
     * @param Model $model
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function loadPolicy(Model $model): void
    {
        $rows = $this->eloquent->field('ptype,v0,v1,v2,v3,v4,v5')
            ->select()
            ->toArray();

        foreach ($rows as $row) {
            $line = implode(', ', array_filter($row, function ($val) {
                return '' != $val && !is_null($val);
            }));
            $this->loadPolicyLine(trim($line), $model);
        }
    }

    /**
     * saves all policy rules to the storage.
     *
     * @param Model $model
     */
    public function savePolicy(Model $model): void
    {
        foreach ($model['p'] as $ptype => $ast) {
            foreach ($ast->policy as $rule) {
                $this->savePolicyLine($ptype, $rule);
            }
        }

        foreach ($model['g'] as $ptype => $ast) {
            foreach ($ast->policy as $rule) {
                $this->savePolicyLine($ptype, $rule);
            }
        }
    }

    /**
     * adds a policy rule to the storage.
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param array $rule
     */
    public function addPolicy(string $sec, string $ptype, array $rule): void
    {
        $this->savePolicyLine($ptype, $rule);
    }

    /**
     * This is part of the Auto-Save feature.
     * @param string $sec
     * @param string $ptype
     * @param array $rule
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function removePolicy(string $sec, string $ptype, array $rule): void
    {
        $instance = $this->eloquent->where('ptype', $ptype);

        foreach ($rule as $key => $value) {
            $instance->where('v' . strval($key), $value);
        }

        $modelRows = $instance->select()->toArray();

        foreach ($modelRows as $model) {
            $this->eloquent->where('id', $model['id'])->delete();
        }
    }

    /**
     * RemoveFilteredPolicy removes policy rules that match the filter from the storage.
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param int $fieldIndex
     * @param string ...$fieldValues
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function removeFilteredPolicy(string $sec, string $ptype, int $fieldIndex, string ...$fieldValues): void
    {
        $instance = $this->eloquent->where('ptype', $ptype);
        foreach (range(0, 5) as $value) {
            if ($fieldIndex <= $value && $value < $fieldIndex + count($fieldValues)) {
                if ('' != $fieldValues[$value - $fieldIndex]) {
                    $instance->where('v' . strval($value), $fieldValues[$value - $fieldIndex]);
                }
            }
        }

        $modelRows = $instance->select()->toArray();

        foreach ($modelRows as $model) {
            $this->eloquent->where('id', $model['id'])->delete();
        }
    }

    /**
     * Filter the rule.
     *
     * @param array $rule
     * @return array
     */
    public function filterRule(array $rule): array
    {
        $rule = array_values($rule);

        $i = count($rule) - 1;
        for (; $i >= 0; $i--) {
            if ($rule[$i] != '' && !is_null($rule[$i])) {
                break;
            }
        }

        return array_slice($rule, 0, $i + 1);
    }

    /**
     * 移除策略
     * @param string $sec
     * @param string $ptype
     * @param int $fieldIndex
     * @param string|null ...$fieldValues
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function _removeFilteredPolicy(string $sec, string $ptype, int $fieldIndex, ?string ...$fieldValues): array
    {
        $count = 0;
        $removedRules = [];

        $instance = $this->eloquent->where('ptype', $ptype);
        foreach (range(0, 5) as $value) {
            if ($fieldIndex <= $value && $value < $fieldIndex + count($fieldValues)) {
                if ('' != $fieldValues[$value - $fieldIndex]) {
                    $instance->where('v' . strval($value), $fieldValues[$value - $fieldIndex]);
                }
            }
        }

        foreach ($instance->select() as $model) {
            $item = $model->hidden(['id', 'ptype'])->toArray();
            $item = $this->filterRule($item);
            $removedRules[] = $item;
            if ($model->delete()) {
                ++$count;
            }
        }

        return $removedRules;
    }

    /**
     * 批量添加策略
     * Adds a policy rules to the storage.
     * This is part of the Auto-Save feature.
     *
     * @param string $sec
     * @param string $ptype
     * @param string[][] $rules
     */
    public function addPolicies(string $sec, string $ptype, array $rules): void
    {
        $cols = [];
        $i = 0;

        foreach ($rules as $rule) {
            $temp['ptype'] = $ptype;
            foreach ($rule as $key => $value) {
                $temp['v' . strval($key)] = $value;
            }
            $cols[$i++] = $temp;
            $temp = [];
        }
        $this->eloquent->insertAll($cols);
    }


    /**
     * 批量更新策略
     * @param string $sec
     * @param string $ptype
     * @param array $oldRules
     * @param array $newRules
     * @return void
     */
    public function updatePolicies(string $sec, string $ptype, array $oldRules, array $newRules): void
    {
        Db::transaction(function () use ($sec, $ptype, $oldRules, $newRules) {
            foreach ($oldRules as $i => $oldRule) {
                $this->updatePolicy($sec, $ptype, $oldRule, $newRules[$i]);
            }
        });
    }

    /**
     * 批量更新策略
     * @param string $sec
     * @param string $ptype
     * @param array $newPolicies
     * @param int $fieldIndex
     * @param string ...$fieldValues
     * @return array
     */
    public function updateFilteredPolicies(string $sec, string $ptype, array $newPolicies, int $fieldIndex, string ...$fieldValues): array
    {
        $oldRules = [];
        DB::transaction(function () use ($sec, $ptype, $fieldIndex, $fieldValues, $newPolicies, &$oldRules) {
            $oldRules = $this->_removeFilteredPolicy($sec, $ptype, $fieldIndex, ...$fieldValues);
            $this->addPolicies($sec, $ptype, $newPolicies);
        });

        return $oldRules;
    }

    /**
     * 批量移除策略
     * @param string $sec
     * @param string $ptype
     * @param array $rules
     * @return void
     */
    public function removePolicies(string $sec, string $ptype, array $rules): void
    {
        Db::transaction(function () use ($sec, $ptype, $rules) {
            foreach ($rules as $rule) {
                $this->removePolicy($sec, $ptype, $rule);
            }
        });
    }

    /**
     * 更新策略
     * @param string $sec
     * @param string $ptype
     * @param array $oldRule
     * @param array $newPolicy
     * @return void
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function updatePolicy(string $sec, string $ptype, array $oldRule, array $newPolicy): void
    {
        $instance = $this->eloquent->where('ptype', $ptype);
        foreach ($oldRule as $key => $value) {
            $instance->where('v' . strval($key), $value);
        }
        $instance = $instance->find();

        foreach ($newPolicy as $key => $value) {
            $column = 'v' . strval($key);
            $instance->$column = $value;
        }

        $instance->save();
    }
}
