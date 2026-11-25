<?php
namespace app\common\library;

/**
 * IP 归属地查询
 *
 * 底层使用 ip2region xdb（composer 依赖 zoujingli/ip2region，包内自带
 * vendor/zoujingli/ip2region/db/ip2region_v4.xdb），不再维护 qqwry.dat：
 *   - 数据随 composer 包更新（composer update zoujingli/ip2region 即可换库）
 *   - 不再需要下载/校验脚本，也不再受第三方 raw 直链可用性影响
 *
 * 默认走包内 IPv4 库；如需自定义库（例如更新版 xdb、或 IPv6 库），
 * 构造时传入路径即可：new IPLocation('/path/to/ip2region_v4.xdb')
 *
 * 库缺失/依赖未安装时不会抛异常：isAvailable() 返回 false，
 * getLocation() 统一返回「未知」，避免阻断登录日志等业务。
 */
class IPLocation
{
    /** 解码策略：file（按需读文件）| vectorIndex（512KB 向量索引）| content（整库入内存） */
    private const CACHE_POLICY = 'file';

    /** @var \Ip2Region|null */
    private $searcher = null;

    /** @var string 实际使用的 xdb 路径（可用于后台展示） */
    private $dbPath = '';

    /** @var string 调用方显式指定的库路径（'' 表示用 vendor 内置库） */
    private $customPath = '';

    /** @var bool 库是否可用 */
    private $available = false;

    /** @var string 库不可用原因 */
    private $loadError = '';

    /** @var string|null 数据构建时间，惰性解析 */
    private $dataVersion = null;

    /**
     * 存储解析结果
     *
     * 字段保持与历史实现一致：area 为「省+市」的组合（兼容老的 region 语义），
     * district 在 ip2region v3 数据中没有对应字段，恒为空。
     */
    private $locationInfo = [
        'country' => '',
        'area' => '',
        'province' => '',
        'city' => '',
        'district' => '',
        'isp' => '',
        'full_address' => ''
    ];

    /** 当前查询的IP */
    private $currentIp;

    private static $instance = null;

    /**
     * 构造函数
     *
     * @param string|null $dbPath 自定义 xdb 路径；null 表示使用 vendor 内置 IPv4 库。
     *                            传相对路径时按项目根目录解析（如 'runtime/ipdata/ip2region_v4.xdb'）。
     *                            显式传入即视为权威位置：文件不存在直接判不可用，不静默回落到内置库。
     */
    public function __construct(?string $dbPath = null)
    {
        $this->customPath = $this->resolvePath($dbPath);
        $this->initSearcher();
    }

    public static function getInstance(?string $dbPath = null)
    {
        if (self::$instance === null) {
            self::$instance = new self($dbPath);
        }
        return self::$instance;
    }

    /**
     * 初始化查询器（不抛异常）
     */
    private function initSearcher(): void
    {
        $this->available = false;
        $this->loadError = '';

        if (!class_exists('\Ip2Region')) {
            $this->loadError = '未安装 ip2region 依赖（zoujingli/ip2region），请执行 composer require zoujingli/ip2region:^3.0';
            return;
        }

        if ($this->customPath !== '' && !is_file($this->customPath)) {
            $this->dbPath = $this->customPath;
            $this->loadError = 'IP数据库文件不存在: ' . $this->customPath;
            return;
        }

        try {
            $this->searcher = new \Ip2Region(self::CACHE_POLICY, $this->customPath !== '' ? $this->customPath : null);
            $info = $this->searcher->getDatabaseInfo();
        } catch (\Throwable $e) {
            $this->searcher = null;
            $this->loadError = '初始化 ip2region 失败: ' . $e->getMessage();
            return;
        }

        $this->dbPath = $this->customPath !== '' ? $this->customPath : (string) ($info['v4_path'] ?? '');

        if ($this->dbPath === '' || !is_file($this->dbPath)) {
            $this->searcher = null;
            $this->loadError = '未找到可用的 ip2region IPv4 数据库（xdb）: '
                . ($this->dbPath !== '' ? $this->dbPath : 'vendor/zoujingli/ip2region/db/ip2region_v4.xdb');
            return;
        }

        $this->dataVersion = null;
        $this->available = true;
    }

    /**
     * 解析自定义库路径（相对路径按项目根目录补全）
     */
    private function resolvePath(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        if (function_exists('root_path')) {
            try {
                return rtrim((string) root_path(), '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
            } catch (\Throwable $e) {
            }
        }

        return dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    private function isAbsolutePath(string $path): bool
    {
        return (bool) preg_match('#^(?:[a-zA-Z]:[\\\\/]|[\\\\/]{1,2})#', $path);
    }

    /**
     * 查询IP地址
     *
     * @param string $ip IP地址
     * @return self
     */
    public function getLocation($ip): self
    {
        $this->currentIp = $ip;
        $this->locationInfo = [
            'country' => '',
            'area' => '',
            'province' => '',
            'city' => '',
            'district' => '',
            'isp' => '',
            'full_address' => ''
        ];

        $ip = (string) $ip;

        if ($this->isLocalIp($ip)) {
            $this->locationInfo['full_address'] = '本地网络';
            return $this;
        }

        if (!$this->available || $this->searcher === null || !filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->locationInfo['full_address'] = '未知';
            return $this;
        }

        try {
            $region = $this->searcher->search($ip);
        } catch (\Throwable $e) {
            $this->locationInfo['full_address'] = '未知';
            return $this;
        }

        $this->locationInfo = $this->parseRegion((string) $region);

        return $this;
    }

    /**
     * 把 ip2region 的 region 串解析为统一字段
     */
    private function parseRegion(string $region): array
    {
        $empty = [
            'country' => '',
            'area' => '',
            'province' => '',
            'city' => '',
            'district' => '',
            'isp' => '',
            'full_address' => '未知'
        ];

        if ($region === '') {
            return $empty;
        }

        $parts = explode('|', $region);
        $country = $this->cleanPart($parts[0] ?? '');
        $province = $this->cleanPart($parts[1] ?? '');
        $city = $this->cleanPart($parts[2] ?? '');
        $isp = $this->cleanPart($parts[3] ?? '');

        $full = $this->joinAddress([$country, $province, $city, $isp]);
        if ($full === '') {
            return $empty;
        }

        return [
            'country' => $country,
            'area' => $this->joinAddress([$province, $city]),
            'province' => $province,
            'city' => $city,
            'district' => '',
            'isp' => $isp,
            'full_address' => $full,
        ];
    }

    /**
     * 清理占位值（ip2region 用 0 表示缺失）
     */
    private function cleanPart(string $part): string
    {
        $part = trim($part);

        if ($part === '' || $part === '0' || $part === '未知' || strcasecmp($part, 'reserved') === 0) {
            return '';
        }

        return $part;
    }

    /**
     * 拼接地址：中文直接相连，非中文之间补空格
     */
    private function joinAddress(array $parts): string
    {
        $address = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if ($address === '') {
                $address = $part;
                continue;
            }

            $address .= preg_match('/\p{Han}/u', $part) ? $part : ' ' . $part;
        }

        return $address;
    }

    /**
     * 判断是否为本地IP
     */
    public function isLocalIp(string $ip): bool
    {
        $localRanges = [
            '127.0.0.0/8',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '169.254.0.0/16',
            '::1/128',
            'fc00::/7',
        ];
        
        $ip = $ip ?? $this->currentIp;

        foreach ($localRanges as $range) {
            if ($this->ipInRange($ip, $range)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * 检查IP是否在CIDR范围内（支持IPv4和IPv6）
     * @param string $ip 要检查的IP地址
     * @param string $cidr CIDR表示法，如：192.168.1.0/24 或 2001:db8::/32
     * @return bool
     */
    public function ipInRange(string $ip, string $cidr): bool
    {
        list($network, $mask) = explode('/', $cidr);
        
        $isIpV4 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $isNetworkV4 = filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;

        if ($isIpV4 !== $isNetworkV4) {
            return false;
        }

        if ($isIpV4) {
            $ipLong = ip2long($ip);
            $networkLong = ip2long($network);
            $maskLong = ~((1 << (32 - $mask)) - 1);
            
            return ($ipLong & $maskLong) === ($networkLong & $maskLong);
        } 
        elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            
            $ipBin = inet_pton($ip);
            $networkBin = inet_pton($network);
            
            if (strlen($ipBin) !== strlen($networkBin)) {
                return false;
            }
            
            $bytes = (int)($mask / 8);
            $bits = $mask % 8;
            
            if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($networkBin, 0, $bytes)) {
                return false;
            }
            
            if ($bits > 0) {
                $ipByte = ord(substr($ipBin, $bytes, 1));
                $networkByte = ord(substr($networkBin, $bytes, 1));
                $maskByte = 0xFF << (8 - $bits);
                
                return ($ipByte & $maskByte) === ($networkByte & $maskByte);
            }
            
            return true;
        }
        
        return false;
    }

    
    /**
     * 获取国家/地区
     */
    public function country()
    {
        return $this->locationInfo['country'];
    }
    
    /**
     * 获取区域
     */
    public function area()
    {
        return $this->locationInfo['area'];
    }
    
    /**
     * 获取省份
     */
    public function province()
    {
        return $this->locationInfo['province'];
    }
    
    /**
     * 获取城市
     */
    public function city()
    {
        return $this->locationInfo['city'];
    }
    
    /**
     * 获取区县
     */
    public function district()
    {
        return $this->locationInfo['district'];
    }
    
    /**
     * 获取运营商
     */
    public function isp()
    {
        return $this->locationInfo['isp'];
    }
    
    /**
     * 获取完整地址
     */
    public function full()
    {
        return $this->locationInfo['full_address'];
    }
    
    /**
     * 获取所有信息
     */
    public function all()
    {
        return $this->locationInfo;
    }

    /**
     * 库是否可用（依赖已安装且 xdb 可读）
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * 实际使用的 xdb 路径
     */
    public function getDbPath(): string
    {
        return $this->dbPath;
    }

    /**
     * 库不可用的原因（可用时为空串）
     */
    public function getError(): string
    {
        return $this->loadError;
    }

    /**
     * 数据构建时间（读 xdb 头部 createdAt），如 2026-08-28 12:43:01；不可用时为空串
     */
    public function getVersion(): string
    {
        if (!$this->available) {
            return '';
        }

        if ($this->dataVersion === null) {
            $this->dataVersion = '';

            if (class_exists('\ip2region\xdb\Util')) {
                try {
                    $header = \ip2region\xdb\Util::loadHeaderFromFile($this->dbPath);
                    if (is_array($header) && !empty($header['createdAt'])) {
                        $this->dataVersion = date('Y-m-d H:i:s', (int) $header['createdAt']);
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        return $this->dataVersion;
    }

    /**
     * ip2region 原始库信息（v4_path / cache_policy / loaded 等），便于后台诊断
     */
    public function getDatabaseInfo(): array
    {
        if ($this->searcher === null) {
            return [];
        }

        try {
            return $this->searcher->getDatabaseInfo();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 链式调用示例支持
     */
    public function then($callback)
    {
        if (is_callable($callback)) {
            $callback($this->locationInfo);
        }
        return $this;
    }
}
