(function (global, factory) {
    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        global.SecureDisplay = factory();
    }
}(typeof window !== 'undefined' ? window : this, function () {
    "use strict";
    
    // 默认配置
    const defaultConfig = {
        // 选择器配置
        selectors: {
            // 加密文本元素选择器
            text: '[data-encrypted]',
            // 加密链接元素选择器
            link: '[data-encrypted-link]',
            // 加密Email元素选择器
            email: '[data-encrypted-email]',
            // 自定义加密元素选择器
            custom: '[data-secure-content]'
        },
        
        // 加密算法配置
        algorithms: {
            // 简单异或加密
            'simple-xor': {
                encrypt: function(plainText, key = 42) {
                    let result = '';
                    for (let i = 0; i < plainText.length; i++) {
                        const charCode = plainText.charCodeAt(i);
                        const encryptedCharCode = charCode ^ key;
                        result += ('0' + encryptedCharCode.toString(16)).slice(-2);
                    }
                    return result;
                },
                decrypt: function(encodedStr, key = 42) {
                    let result = '';
                    for (let i = 0; i < encodedStr.length; i += 2) {
                        const hexValue = encodedStr.substr(i, 2);
                        const decodedCharCode = parseInt(hexValue, 16) ^ key;
                        result += String.fromCharCode(decodedCharCode);
                    }
                    return result;
                }
            },
            
            // Base64编码（注意：这不是加密，只是编码）
            'base64': {
                encrypt: function(plainText) {
                    return btoa(unescape(encodeURIComponent(plainText)));
                },
                decrypt: function(encodedStr) {
                    return decodeURIComponent(escape(atob(encodedStr)));
                }
            },
            
            // 字符移位算法
            'character-shift': {
                encrypt: function(plainText, shift = 3) {
                    let result = '';
                    for (let i = 0; i < plainText.length; i++) {
                        let charCode = plainText.charCodeAt(i);
                        // 对可打印ASCII字符进行移位
                        if (charCode >= 32 && charCode <= 126) {
                            charCode = ((charCode - 32 + shift) % 95) + 32;
                        }
                        result += String.fromCharCode(charCode);
                    }
                    return btoa(unescape(encodeURIComponent(result)));
                },
                decrypt: function(encodedStr, shift = 3) {
                    let text = decodeURIComponent(escape(atob(encodedStr)));
                    let result = '';
                    for (let i = 0; i < text.length; i++) {
                        let charCode = text.charCodeAt(i);
                        // 对可打印ASCII字符进行反向移位
                        if (charCode >= 32 && charCode <= 126) {
                            charCode = ((charCode - 32 - shift + 95) % 95) + 32;
                        }
                        result += String.fromCharCode(charCode);
                    }
                    return result;
                }
            }
        },
        
        // 默认解密算法
        defaultAlgorithm: 'simple-xor',
        
        // 默认解密密钥
        defaultKey: 42,
        
        // 是否自动处理动态添加的内容
        observeDOM: true,
        
        // 错误处理回调
        onError: function(error) {
            console.error('SecureDisplay Error:', error);
        },
        
        // 成功解密回调
        onDecrypt: function(original, decrypted, element) {
            console.log('Decrypt Success:', original, '->', decrypted);
        },
        
        // 成功加密回调
        onEncrypt: function(original, encrypted) {
            console.log('Encrypt Success:', original, '->', encrypted);
        }
    };
    
    // 主组件类
    class SecureDisplay {
        constructor(config = {}) {
            this.config = this.deepMerge(defaultConfig, config);
            this.observer = null;
            this.init();
        }
        
        // 深度合并对象
        deepMerge(target, source) {
            const result = {...target};
            for (const key in source) {
                if (source[key] && typeof source[key] === 'object' && !Array.isArray(source[key])) {
                    result[key] = this.deepMerge(target[key] || {}, source[key]);
                } else {
                    result[key] = source[key];
                }
            }
            return result;
        }
        
        // 初始化组件
        init() {
            this.processDocument();
            
            if (this.config.observeDOM) {
                this.setupMutationObserver();
            }
        }
        
        // 处理整个文档
        processDocument(context = document) {
            this.processElements(context);
        }
        
        // 处理所有加密元素
        processElements(context) {
            // 处理加密文本
            this.processTextElements(context);
            
            // 处理加密链接
            this.processLinkElements(context);
                        
            // 处理加密Email
            this.processEmailElements(context);
            
            // 处理自定义加密元素
            this.processCustomElements(context);
        }
        
        // 处理加密文本元素
        processTextElements(context) {
            const elements = context.querySelectorAll(this.config.selectors.text);
            elements.forEach(element => {
                this.decryptAndReplaceText(element, 'data-encrypted');
            });
        }
        
        // 处理加密链接元素
        processLinkElements(context) {
            const elements = context.querySelectorAll(this.config.selectors.link);
            elements.forEach(element => {
                this.decryptAndReplaceLink(element, 'data-encrypted-link');
            });
        }
        
        // 处理加密Email元素
        processEmailElements(context) {
            const elements = context.querySelectorAll(this.config.selectors.email);
            elements.forEach(element => {
                this.decryptAndReplaceEmail(element, 'data-encrypted-email');
            });
        }
        
        // 处理自定义加密元素
        processCustomElements(context) {
            const elements = context.querySelectorAll(this.config.selectors.custom);
            elements.forEach(element => {
                const encryptedData = element.getAttribute('data-secure-content');
                const algorithm = element.getAttribute('data-algorithm') || this.config.defaultAlgorithm;
                const key = parseInt(element.getAttribute('data-key')) || this.config.defaultKey;
                
                if (encryptedData) {
                    this.decryptAndReplaceCustom(element, encryptedData, algorithm, key);
                }
            });
        }
        
        // 解密并替换文本内容
        decryptAndReplaceText(element, attributeName) {
            try {
                const encryptedData = element.getAttribute(attributeName);
                if (!encryptedData) return;
                
                const algorithm = element.getAttribute('data-algorithm') || this.config.defaultAlgorithm;
                const key = parseInt(element.getAttribute('data-key')) || this.config.defaultKey;
                
                const decrypted = this.decrypt(encryptedData, algorithm, key);
                element.textContent = decrypted;
                
                // 触发成功回调
                this.config.onDecrypt(encryptedData, decrypted, element);
            } catch (error) {
                this.config.onError(error);
            }
        }
        
        // 解密并替换链接
        decryptAndReplaceLink(element, attributeName) {
            try {
                const encryptedData = element.getAttribute(attributeName);
                if (!encryptedData) return;
                
                const algorithm = element.getAttribute('data-algorithm') || this.config.defaultAlgorithm;
                const key = parseInt(element.getAttribute('data-key')) || this.config.defaultKey;
                
                const decrypted = this.decrypt(encryptedData, algorithm, key);
                element.href = decrypted;
                
                // 触发成功回调
                this.config.onDecrypt(encryptedData, decrypted, element);
            } catch (error) {
                this.config.onError(error);
            }
        }
        
        // 解密并替换Email
        decryptAndReplaceEmail(element, attributeName) {
            try {
                const encryptedData = element.getAttribute(attributeName);
                if (!encryptedData) return;
                
                const algorithm = element.getAttribute('data-algorithm') || this.config.defaultAlgorithm;
                const key = parseInt(element.getAttribute('data-key')) || this.config.defaultKey;
                
                const decrypted = this.decrypt(encryptedData, algorithm, key);
                element.href = 'mailto:' + decrypted;
                element.textContent = decrypted;
                
                // 触发成功回调
                this.config.onDecrypt(encryptedData, decrypted, element);
            } catch (error) {
                this.config.onError(error);
            }
        }
        
        // 解密并替换自定义元素
        decryptAndReplaceCustom(element, encryptedData, algorithm, key) {
            try {
                const decrypted = this.decrypt(encryptedData, algorithm, key);
                element.textContent = decrypted;
                
                // 触发成功回调
                this.config.onDecrypt(encryptedData, decrypted, element);
            } catch (error) {
                this.config.onError(error);
            }
        }
        
        // 通用解密方法
        decrypt(encodedStr, algorithmName, key) {
            const algorithm = this.config.algorithms[algorithmName];
            if (!algorithm) {
                throw new Error(`Unknown decryption algorithm: ${algorithmName}`);
            }
            
            if (!algorithm.decrypt) {
                throw new Error(`Algorithm ${algorithmName} does not support decryption`);
            }
            
            return algorithm.decrypt(encodedStr, key);
        }
        
        // 通用加密方法
        encrypt(plainText, algorithmName, key) {
            const algorithm = this.config.algorithms[algorithmName];
            if (!algorithm) {
                throw new Error(`Unknown encryption algorithm: ${algorithmName}`);
            }
            
            if (!algorithm.encrypt) {
                throw new Error(`Algorithm ${algorithmName} does not support encryption`);
            }
            
            const result = algorithm.encrypt(plainText, key);
            this.config.onEncrypt(plainText, result);
            return result;
        }
        
        // 添加新的加密算法
        addAlgorithm(name, encryptFunction, decryptFunction) {
            this.config.algorithms[name] = {
                encrypt: encryptFunction,
                decrypt: decryptFunction
            };
        }

        // 设置MutationObserver监听DOM变化
        setupMutationObserver() {
            if (typeof MutationObserver === 'undefined') return;
            
            this.observer = new MutationObserver(mutations => {
                mutations.forEach(mutation => {
                    mutation.addedNodes.forEach(node => {
                        if (node.nodeType === Node.ELEMENT_NODE) {
                            this.processElements(node);
                        }
                    });
                });
            });
            
            this.observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        }
        
        // 手动处理新元素
        processNewElement(element) {
            this.processElements(element);
        }
        
        // 销毁组件
        destroy() {
            if (this.observer) {
                this.observer.disconnect();
            }
        }
    }
    
    // 将组件暴露到全局作用域
    window.SecureDisplay = SecureDisplay;
    
    // 自动初始化
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            window.secureDisplay = new SecureDisplay();
        });
    } else {
        window.secureDisplay = new SecureDisplay();
    }
    return SecureDisplay;
}));
