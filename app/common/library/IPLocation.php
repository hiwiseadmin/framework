<?php
namespace app\common\library;
use think\Exception;

class IPLocation
{
    private $dbPath;
    private $fileHandle;
    private $mm;
    private $indexBegin;
    private $indexEnd;
    private $totalIndexes;
    
    // 标记字节常量
    const REDIRECT_MODE_1 = 0x01;
    const REDIRECT_MODE_2 = 0x02;
    
    // 存储解析结果
    private $locationInfo = [
        'country' => '',
        'area' => '',
        'province' => '',
        'city' => '',
        'district' => '',
        'isp' => '',
        'full_address' => ''
    ];
    
    // 当前查询的IP
    private $currentIp;

    // 单例实例
    private static $instance = null;
    
    /**
     * 构造函数
     * @param string $dbPath 纯真IP数据库文件路径
     */
    public function __construct($dbPath = 'QQWry.dat')
    {
        $this->dbPath = __DIR__ . '/ipdata/' . $dbPath;
        $this->initDatabase();
    }
    
    //静态方法获取实例
    public static function getInstance($dbPath = 'qqwry.dat')
    {
        if (self::$instance === null) {
            self::$instance = new self($dbPath);
        }
        return self::$instance;
    }
    
    
    /**
     * 查询IP地址
     * @param string $ip IP地址
     * @return self
     */
    public function getLocation($ip): self
    {
        $this->currentIp = $ip;
        // 重置位置信息
        $this->locationInfo = [
            'country' => '',
            'area' => '',
            'province' => '',
            'city' => '',
            'district' => '',
            'isp' => '',
            'full_address' => ''
        ];

        if($this->isLocalIp($ip)){
            $this->locationInfo = [
                'country' => '',
                'area' => '',
                'province' => '',
                'city' => '',
                'district' => '',
                'isp' => '',
                'full_address' => '本地网络'
            ];
            return $this;
        }
        
        $recordOffset = $this->findRecord($ip);
        if ($recordOffset !== false) {
            list($country, $area) = $this->parseLocation($recordOffset);
            $this->locationInfo = $this->parseDetailAddress($country, $area);
        }
        
        return $this;
    }
    
    /**
     * 判断是否为本地IP
     */
    public function isLocalIp(string $ip): bool
    {
        $localRanges = [
            '127.0.0.0/8',    // 本地回环
            '10.0.0.0/8',     // 私有网络
            '172.16.0.0/12',  // 私有网络
            '192.168.0.0/16', // 私有网络
            '169.254.0.0/16', // 链路本地
            '::1/128',        // IPv6本地回环
            'fc00::/7',       // IPv6私有网络
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
        // 分割CIDR为网络地址和掩码
        list($network, $mask) = explode('/', $cidr);
        
        // 确定IP版本
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) != filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // IPv4
            $ipLong = ip2long($ip);
            $networkLong = ip2long($network);
            $maskLong = ~((1 << (32 - $mask)) - 1);
            
            return ($ipLong & $maskLong) === ($networkLong & $maskLong);
        } 
        elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // IPv6
            
            // 将IPv6地址转换为二进制字符串
            $ipBin = inet_pton($ip);
            $networkBin = inet_pton($network);
            
            if (strlen($ipBin) !== strlen($networkBin)) {
                return false;
            }
            
            // 计算需要比较的字节数
            $bytes = (int)($mask / 8);
            $bits = $mask % 8;
            
            // 比较完整字节
            if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($networkBin, 0, $bytes)) {
                return false;
            }
            
            // 比较剩余位
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

    // 链式方法
    
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
     * 链式调用示例支持
     */
    public function then($callback)
    {
        if (is_callable($callback)) {
            $callback($this->locationInfo);
        }
        return $this;
    }

    /**
     * 初始化数据库
     */
    private function initDatabase()
    {
        if (!file_exists($this->dbPath)) {
            throw new Exception("IP数据库文件不存在: " . $this->dbPath);
        }
        
        $this->fileHandle = fopen($this->dbPath, 'rb');
        if (!$this->fileHandle) {
            throw new Exception("无法打开IP数据库文件");
        }
        
        // 读取文件头
        $header = fread($this->fileHandle, 8);
        $this->indexBegin = $this->bytesToLong($header[0], $header[1], $header[2], $header[3]);
        $this->indexEnd = $this->bytesToLong($header[4], $header[5], $header[6], $header[7]);
        $this->totalIndexes = ($this->indexEnd - $this->indexBegin) / 7 + 1;
    }
    
    /**
     * 字节转长整型
     */
    private function bytesToLong($a, $b, $c, $d)
    {
        return (ord($a) << 0) | (ord($b) << 8) | (ord($c) << 16) | (ord($d) << 24);
    }
    
    /**
     * IP地址转长整型
     */
    private function ipToLong($ip)
    {
        return sprintf('%u', ip2long($ip));
    }
    
    /**
     * 读取字符串
     */
    private function readString($offset = null)
    {
        if ($offset !== null) {
            fseek($this->fileHandle, $offset);
        }
        
        $string = '';
        while (($char = fread($this->fileHandle, 1)) !== chr(0)) {
            $string .= $char;
        }
        
        // 转换为UTF-8编码
        return mb_convert_encoding($string, 'UTF-8', 'GBK');
    }
    
    /**
     * 查找IP记录
     */
    private function findRecord($ip)
    {
        $ipLong = $this->ipToLong($ip);
        $left = 0;
        $right = $this->totalIndexes - 1;
        
        // 二分查找
        while ($left <= $right) {
            $mid = floor(($left + $right) / 2);
            $offset = $this->indexBegin + $mid * 7;
            
            fseek($this->fileHandle, $offset);
            $startIp = $this->bytesToLong(...str_split(fread($this->fileHandle, 4)));
            $recordPointer = $this->bytesToLong(...array_merge(str_split(fread($this->fileHandle, 3)), [chr(0)]));
            
            fseek($this->fileHandle, $recordPointer);
            $endIp = $this->bytesToLong(...str_split(fread($this->fileHandle, 4)));
            
            if ($ipLong < $startIp) {
                $right = $mid - 1;
            } elseif ($ipLong > $endIp) {
                $left = $mid + 1;
            } else {
                return $recordPointer + 4; // 跳过结束IP
            }
        }
        
        return false;
    }
    
    /**
     * 解析位置信息
     */
    private function parseLocation($offset)
    {
        fseek($this->fileHandle, $offset);
        $flag = ord(fread($this->fileHandle, 1));
        
        if ($flag === self::REDIRECT_MODE_1) {
            // 模式1重定向
            $newOffset = $this->bytesToLong(...array_merge(str_split(fread($this->fileHandle, 3)), [chr(0)]));
            return $this->parseLocation($newOffset);
        } elseif ($flag === self::REDIRECT_MODE_2) {
            // 模式2重定向
            $newOffset = $this->bytesToLong(...array_merge(str_split(fread($this->fileHandle, 3)), [chr(0)]));
            $country = $this->readString($newOffset);
            fseek($this->fileHandle, $offset + 4);
            $area = $this->readArea();
            return [$country, $area];
        } else {
            // 无重定向
            fseek($this->fileHandle, $offset);
            $country = $this->readString();
            $area = $this->readArea();
            return [$country, $area];
        }
    }
    
    /**
     * 读取区域信息
     */
    private function readArea()
    {
        $flag = ord(fread($this->fileHandle, 1));
        
        if ($flag === self::REDIRECT_MODE_1 || $flag === self::REDIRECT_MODE_2) {
            $offset = $this->bytesToLong(...array_merge(str_split(fread($this->fileHandle, 3)), [chr(0)]));
            $currentPos = ftell($this->fileHandle);
            $area = $this->readString($offset);
            fseek($this->fileHandle, $currentPos);
            return $area;
        } else {
            fseek($this->fileHandle, -1, SEEK_CUR);
            return $this->readString();
        }
    }
    
    /**
     * 解析详细地址信息
     * @param string $country 省市地区
     * @param string $area 区域
     * @return array
     */
    private function parseDetailAddress($country, $area): array
    {
        // 初始化地址信息
        $info = [
            'country' => $country,
            'area' => $area,
            'province' => '',
            'city' => '',
            'district' => '',
            'isp' => '',
            'full_address' => $country . $area
        ];
        
        // 解析省份、城市、运营商等信息
        $pattern = '/(.*?)(省|市|自治区|特别行政区|地区|州)?/u';
        preg_match($pattern, $country, $matches);
        
        if (!empty($matches[1])) {
            // 这里可以添加更详细的地理信息解析逻辑
            // 例如根据关键词识别省份、城市等
            
            // 简单的关键词匹配
            $provinceKeywords = ['北京', '上海', '天津', '重庆', '河北', '山西', '辽宁', '吉林', '黑龙江', 
                                '江苏', '浙江', '安徽', '福建', '江西', '山东', '河南', '湖北', '湖南',
                                '广东', '海南', '四川', '贵州', '云南', '陕西', '甘肃', '青海', '台湾',
                                '内蒙古', '广西', '西藏', '宁夏', '新疆', '香港', '澳门'];
            
            foreach ($provinceKeywords as $province) {
                if (strpos($country, $province) === 0) {
                    $info['province'] = $province;
                    $info['city'] = str_replace($province, '', $country);
                    break;
                }
            }
        }
        
        // 解析ISP信息
        $ispKeywords = ['电信', '联通', '移动', '铁通', '长城宽带', '教育网', '科技网'];
        foreach ($ispKeywords as $isp) {
            if (strpos($area, $isp) !== false) {
                $info['isp'] = $isp;
                break;
            }
        }
        
        return $info;
    }
    
    /**
     * 析构函数
     */
    public function __destruct()
    {
        if ($this->fileHandle) {
            fclose($this->fileHandle);
        }
    }
}

// 使用示例
/*
try {
    $ipLocation = new IPLocation('QQWry.dat');
    
    // 链式调用示例
    $result = $ipLocation->get('8.8.8.8')
        ->then(function($info) {
            echo "IP地址信息: " . $info['full_address'] . "\n";
        })
        ->all();
    
    print_r($result);
    
    // 单独获取各个部分
    echo "国家: " . $ipLocation->get('114.114.114.114')->country() . "\n";
    echo "省份: " . $ipLocation->get('114.114.114.114')->province() . "\n";
    echo "城市: " . $ipLocation->get('114.114.114.114')->city() . "\n";
    echo "运营商: " . $ipLocation->get('114.114.114.114')->isp() . "\n";
    echo "完整地址: " . $ipLocation->get('114.114.114.114')->full() . "\n";
    
} catch (Exception $e) {
    echo "错误: " . $e->getMessage() . "\n";
}
*/
?>