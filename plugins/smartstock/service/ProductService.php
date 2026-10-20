<?php
namespace plugin\smartstock\service;

use plugin\smartstock\model\Product;
use plugin\smartstock\model\ProductSpecValue;
use think\facade\Db;

/**
 * 商品服务
 *
 * 职责：
 * - SKU 编码生成（唯一性校验）
 * - 规格值关联管理
 * - 商品批量查询
 */
class ProductService
{
    /**
     * 生成唯一 SKU 编码
     *
     * 规则：商品名称首字母 + 分类ID + 6位随机数
     * 校验唯一性后返回
     *
     * @param string $name 商品名称
     * @param int $categoryId 分类ID
     * @return string SKU 编码
     */
    public function generateSkuCode(string $name, int $categoryId): string
    {
        // 取商品名称首字母（中文取首字拼音首字母简化处理，英文取首字母）
        $initial = $this->getInitials($name);

        // 生成 SKU 编码
        $maxAttempts = 100;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $random   = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
            $skuCode  = strtoupper($initial) . $categoryId . $random;

            // 校验唯一性
            $exists = Product::where('sku_code', $skuCode)->find();
            if (!$exists) {
                return $skuCode;
            }
        }

        // 如果 100 次都碰撞，使用更长的随机数
        return strtoupper($initial) . $categoryId . str_pad(mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
    }

    /**
     * 取名称首字母
     */
    private function getInitials(string $name): string
    {
        $initial = '';
        $chars = mb_str_split($name);
        foreach (array_slice($chars, 0, 3) as $char) {
            if (preg_match('/[a-zA-Z]/', $char)) {
                $initial .= strtoupper($char[0]);
            } elseif (preg_match('/[\x{4e00}-\x{9fff}]/u', $char)) {
                // 中文字符，使用 Unicode 码点后两位作为区分键
                $codePoint = mb_ord($char);
                $initial .= strtoupper(dechex($codePoint % 26 + 10)); // 0x0A-0x23
            } else {
                $initial .= 'X';
            }
        }

        return $initial ?: 'PROD';
    }

    /**
     * 保存商品规格值
     *
     * @param int $productId 商品ID
     * @param array $specValues 规格值数组 [['spec_id' => 1, 'value' => '红色'], ...]
     */
    public function saveSpecValues(int $productId, array $specValues): void
    {
        Db::startTrans();
        try {
            // 删除旧规格值
            ProductSpecValue::where('product_id', $productId)->delete();

            // 批量插入新规格值
            $summaryParts = [];
            foreach ($specValues as $sv) {
                if (empty($sv['value'])) {
                    continue;
                }

                (new ProductSpecValue())->save([
                    'product_id' => $productId,
                    'spec_id'    => $sv['spec_id'],
                    'value'      => $sv['value'],
                ]);

                $summaryParts[] = $sv['value'];
            }

            // 更新 product.spec_summary 字段
            $product = Product::find($productId);
            if ($product) {
                $product->spec_summary = implode(' / ', $summaryParts);
                $product->save();
            }

            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 获取商品的可用库存列表
     *
     * @param int $productId
     * @return array
     */
    public function getAvailableStock(int $productId): array
    {
        return Db::name('smartstock_stock')
            ->alias('s')
            ->join('smartstock_warehouse w', 's.warehouse_id = w.id')
            ->where('s.product_id', $productId)
            ->field('s.*, w.name as warehouse_name')
            ->select()
            ->toArray();
    }
}
