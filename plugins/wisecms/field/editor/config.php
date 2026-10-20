<?php
return [
    'name' => '编辑器',
    'field_type' => 'mediumtext',      // 数据库字段类型
    'allow_table' => 0,                // 不允许作为主表字段
    'allow_index' => 0,                // 不允许建立索引
    'min_length' => 0,
    'max_length' => '',
    'allow_search' => 0,               // 不允许搜索
    'allow_fulltext' => 1,             // 允许全文搜索
    'allow_unique' => 0,               // 不允许唯一
    'allow_required' => 1,             // 允许必填
    'allow_default' => 1,              // 允许默认值
    'allow_setting' => 1,              // 允许设置
    'sort_weight' => 100,              // 排序权重
];