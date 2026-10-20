(function (root, factory) {
  if (typeof define === 'function' && define.amd) {
    // AMD (RequireJS)
    define([], factory);
  } else if (typeof exports === 'object' && typeof module !== 'undefined') {
    // CommonJS (Node.js)
    module.exports = factory();
  } else {
    // 浏览器全局变量
    root.Cookie = factory();
  }
}(this, function () {
    const Cookie = {
        defaultOptions: {
            path: '/',
            secure: window.location.protocol === 'https:',
            sameSite: 'Lax',
        },
        /**
         * 设置Cookie
         * @param {string} name - Cookie名称
         * @param {string} value - Cookie值
         * @param {Object} options - 配置选项
         * @param {number} options.days - 过期天数
         * @param {number} options.hours - 过期小时数
         * @param {number} options.minutes - 过期分钟数
         * @param {string} options.path - Cookie路径
         * @param {string} options.domain - Cookie域
         * @param {boolean} options.secure - 是否仅HTTPS
         * @param {string} options.sameSite - SameSite属性
         */
        set(name, value, options = {}) {
            let cookieString = `${encodeURIComponent(name)}=${encodeURIComponent(value)}`;

            // 处理过期时间
            if (options.days || options.hours || options.minutes) {
                const expires = new Date();

                if (options.days) expires.setDate(expires.getDate() + options.days);
                if (options.hours) expires.setHours(expires.getHours() + options.hours);
                if (options.minutes) expires.setMinutes(expires.getMinutes() + options.minutes);

                cookieString += `; expires=${expires.toUTCString()}`;
            } else if (options.expires && options.expires instanceof Date) {
                cookieString += `; expires=${options.expires.toUTCString()}`;
            }

            // 其他选项
            if (options.path) cookieString += `; path=${options.path}`;
            if (options.domain) cookieString += `; domain=${options.domain}`;
            if (options.secure) cookieString += '; secure';
            if (options.sameSite) cookieString += `; samesite=${options.sameSite}`;

            document.cookie = cookieString;
        },

        /**
         * 获取Cookie值
         * @param {string} name - Cookie名称
         * @param {string|null} defaultValue - 默认值
         * @param {Object} options - 配置选项
         * @returns {string|null} Cookie值
         */
        get(name, defaultValue = null, options = {}) {
            const nameEQ = encodeURIComponent(name) + "=";
            const cookies = document.cookie.split(';');

            for (let i = 0; i < cookies.length; i++) {
                let cookie = cookies[i];
                while (cookie.charAt(0) === ' ') {
                    cookie = cookie.substring(1);
                }
                if (cookie.indexOf(nameEQ) === 0) {
                    return decodeURIComponent(cookie.substring(nameEQ.length));
                }
            }
            return defaultValue;
        },

        /**
         * 设置JSON数据到Cookie
         * @param {string} name - Cookie名称
         * @param {Object} data - JSON数据
         * @param {Object} options - 配置选项
         */
        setJSON(name, data, options = {}) {
            try {
                const jsonString = JSON.stringify(data);
                return this.setWithSizeCheck(name, jsonString, options);
            } catch (error) {
                console.error('无法序列化JSON数据:', error);
                return false;
            }
        },

        /**
         * 获取JSON格式的Cookie
         * @param {string} name - Cookie名称
         * @param {Object|null} defaultValue - 默认值
         * @param {Object} options - 配置选项
         * @returns {Object|null} JSON数据
         */
        getJSON(name, defaultValue = null, options = {}) {
            try {
                const value = this.get(name, defaultValue, options);
                return value ? JSON.parse(value) : defaultValue;
            } catch (error) {
                console.error('无法解析JSON数据:', error);
                return defaultValue;
            }
        },

        /**
         * 设置会话Cookie（浏览器关闭时失效）
         * @param {string} name - Cookie名称
         * @param {string} value - Cookie值
         * @param {Object} options - 配置选项
         */
        setSession(name, value, options = {}) {
            CookieManager.set(name, value, {
                ...defaultOptions,
                ...options
                // 不设置expires，即为会话Cookie
            });
        },

        /**
         * 设置长期Cookie
         * @param {string} name - Cookie名称
         * @param {string} value - Cookie值
         * @param {number} days - 过期天数
         * @param {Object} options - 配置选项
         */
        setPersistent(name, value, days = 365, options = {}) {  
            CookieManager.set(name, value, {
                ...defaultOptions,
                days,
                ...options
            });
        },

        /**
         * 安全地获取Cookie（防XSS）
         * @param {string} name - Cookie名称
         * @returns {string|null} Cookie值
         */
        getSecure(name) {
            const value = CookieManager.get(name);
            // 添加额外的安全验证逻辑
            if (value && this.isValidValue(value)) {
                return value;
            }
            return null;
        },

        /**
         * 验证Cookie值是否有效
         * @param {string} value - Cookie值
         * @returns {boolean}
         */
        isValidValue(value) {
            // 实现验证逻辑，如检查格式、长度等
            return typeof value === 'string' && value.length < 4096;
        },

        /**
         * 跟踪用户同意（GDPR合规）
         * @param {string} consentType - 同意类型（如'analytics'）
         * @param {boolean} value - 用户同意值（true/false）
         */
        setConsent(consentType, value) {
            const consentData = {
                [consentType]: value,
                timestamp: new Date().toISOString(),
                version: '1.0'
            };

            CookieManager.setJSON('cookie_consent', consentData, {
                days: 365,
                ...defaultOptions
            });
        },

        /**
         * 检查用户是否同意了特定类型的Cookie
         * @param {string} consentType - 同意类型（如'analytics'）
         * @returns {boolean}
         */
        hasConsent(consentType) {
            const consent = CookieManager.getJSON('cookie_consent');
            return consent && consent[consentType] === true;
        },

        /**
         * 删除Cookie
         * @param {string} name - Cookie名称
         * @param {Object} options - 路径和域选项
         */
        delete(name, options = {}) {
            this.set(name, '', {
                expires: new Date(0),
                path: options.path,
                domain: options.domain
            });
        },

        /**
         * 检查Cookie是否存在
         * @param {string} name - Cookie名称
         * @returns {boolean}
         */
        has(name) {
            return this.get(name) !== null;
        },

        /**
         * 获取所有Cookie
         * @returns {Object} 所有Cookie的键值对
         */
        getAll() {
            const cookies = {};
            const cookieArray = document.cookie.split(';');

            cookieArray.forEach(cookie => {
                const [key, value] = cookie.trim().split('=');
                if (key) {
                    cookies[decodeURIComponent(key)] = decodeURIComponent(value || '');
                }
            });

            return cookies;
        },

        /**
         * 清空所有Cookie
         * @param {Object} options - 路径和域选项
         */
        clearAll(options = {}) {
            const cookies = this.getAll();
            Object.keys(cookies).forEach(name => {
                this.delete(name, options);
            });
        }
    };

    return Cookie;
}));