<?php
namespace plugin\wisecms\service\seo;

/**
 * SEO检查接口
 */
interface SeoCheckInterface
{
    public function check(array $content, array $category): array;
    public function getName(): string;
    public function getWeight(): float;
}
