<?php

namespace app\common\model\traits;

use think\exception\ValidateException;

/**
 * 模型自动验证 Trait
 *
 * 自动查找规则（优先级：1.传入的 $rules 参数 → 2.模型的 $validateRule 属性 → 3.上层 validate 目录下同名校验类（支持场景））
 *
 * 引入本 Trait 的模型（如 BaseModel 及其子类）实例可直接调用以下对外方法：
 *
 * @method bool         validateData(array $data, array $rules = [], string $scene = '')  执行数据验证，按三级优先级自动查找规则；校验失败返回 false，并通过 getValidateError() 取错误信息
 * @method bool         validateOrFail(array $data, array $rules = [], string $scene = '') 执行数据验证，校验失败直接抛出 think\exception\ValidateException，成功返回 true
 * @method string|array getValidateError()                                                获取最近一次验证失败的错误信息（需在 validateData() 返回 false 后调用）
 *
 * 使用示例：
 * ```php
 * $model = new User();
 *
 * // 方式一：使用模型内置规则（$validateRule 或 validate 目录同名验证类）
 * if (!$model->validateData($data, [], 'create')) {
 *     return $model->getValidateError();
 * }
 *
 * // 方式二：临时传入规则
 * $model->validateData($data, ['name' => 'require|max:25', 'email' => 'email']);
 *
 * // 方式三：失败即抛异常
 * $model->validateOrFail($data, [], 'edit');
 * ```
 */
trait DataValidation
{
    /**
     * 验证错误信息
     * @var string|array
     */
    protected $validateError = '';

    /**
     * 模型验证规则（优先级2，低于传入的 $rules 参数）
     * 格式与 Validate::rule() 一致，例如：['name' => 'require|max:25', 'email' => 'email']
     * @var array
     */
    protected $validateRule = [];

    /**
     * 执行数据验证
     *
     * @param array $data 待验证数据
     * @param array $rules 验证规则（优先级最高）
     * @param string $scene 验证场景
     * @return bool
     */
    public function validateData(array $data, array $rules = [], string $scene = ''): bool
    {
        // 优先级1：传入的 $rules 参数
        if (!empty($rules)) {
            $validate = new \think\Validate();
            $validate->rule($rules);

            if (!$validate->check($data)) {
                $this->validateError = $validate->getError();
                return false;
            }
            return true;
        }

        // 优先级2：模型的 $validateRule 属性
        if (property_exists($this, 'validateRule') && !empty($this->validateRule)) {
            $validate = new \think\Validate();
            $validate->rule($this->validateRule);

            if (!$validate->check($data)) {
                $this->validateError = $validate->getError();
                return false;
            }
            return true;
        }

        // 优先级3：上层 validate 目录下同名校验类（支持场景）
        $modelClass    = get_class($this);
        $validateClass = preg_replace('/\\\\model\\\\/i', '\validate\\', $modelClass);

        // 类名确实发生了替换且验证类存在时执行校验
        if ($validateClass !== $modelClass && class_exists($validateClass)) {
            /** @var \think\Validate $validator */
            $validator = new $validateClass();

            if ($scene !== '' && method_exists($validator, 'hasScene') && $validator->hasScene($scene)) {
                $validator->scene($scene);
            }

            if (!$validator->check($data)) {
                $this->validateError = $validator->getError();
                return false;
            }
            return true;
        }

        // 无任何验证规则：若模型声明了 $validateRule 却未被捕获，视为配置遗漏，避免静默放行
        if (isset($this->validateRule)) {
            $this->validateError = '未找到对应的验证规则或验证类';
            return false;
        }

        // 确无验证规则，默认通过
        return true;
    }

    /**
     * 验证并抛出异常（失败时直接抛异常）
     *
     * @param array $data
     * @param array $rules
     * @param string $scene
     * @return true
     * @throws ValidateException
     */
    public function validateOrFail(array $data, array $rules = [], string $scene = ''): bool
    {
        if (!$this->validateData($data, $rules, $scene)) {
            throw new ValidateException($this->validateError);
        }
        return true;
    }

    public function getValidateError(): string|array
    {
        return $this->validateError;
    }
}
