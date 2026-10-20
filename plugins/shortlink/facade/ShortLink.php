<?php
namespace plugin\shortlink\facade;

use think\Facade;

/**
 * @method static \plugin\shortlink\model\Link create(string|array $data, int $adminId = 0)
 * @method static \plugin\shortlink\model\Link[] batchCreate(array $urls, array $options = [], int $adminId = 0)
 * @method static \plugin\shortlink\model\Link|null parse(string $code)
 * @method static string getUrl(string $code)
 * @method static bool delete(int|array $ids)
 * @method static \plugin\shortlink\model\Link setStatus(int $id, int $status)
 * @method static bool isAvailable(\plugin\shortlink\model\Link $link)
 * @method static int getDefaultGroupId()
 */
class ShortLink extends Facade
{
    protected static function getFacadeClass()
    {
        return \plugin\shortlink\service\ShortLinkService::class;
    }
}
