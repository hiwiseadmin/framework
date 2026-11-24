/**
 * WiseApi-Crypto — 浏览器端传输加密 SDK (ESM)
 *
 * 使用 Web Crypto API 实现 RSA-OAEP + AES-256-GCM 信封加密，
 * 零外部依赖，支持所有现代浏览器（Chrome 37+ / Firefox 34+ / Safari 11+）。
 *
 * 使用方式：
 *
 *   import { ApiCrypto } from './wise-api-crypto.js';
 *
 *   const crypto = new ApiCrypto();
 *
 *   // 1. 获取服务端公钥
 *   const keyInfo = await crypto.fetchPublicKey('/api/encrypt/public-key');
 *
 *   // 2. 加密请求
 *   const encrypted = await crypto.encryptRequest(
 *     { username: 'test', action: 'transfer' },
 *     keyInfo.publicKey,
 *     keyInfo.keyId
 *   );
 *
 *   // 3. 发送请求
 *   const resp = await fetch('/api/v1/xxx', {
 *     method: 'POST',
 *     headers: { 'Content-Type': 'application/json' },
 *     body: JSON.stringify(encrypted),
 *   });
 *
 *   // 4. 解密响应
 *   const result = await crypto.decryptResponse(await resp.json());
 *
 * @module wise-api-crypto
 */

/**
 * 公钥信息
 *
 * @typedef {Object} PublicKeyInfo
 * @property {string} keyId      密钥版本 ID
 * @property {string} publicKey  PEM 格式 RSA 公钥
 * @property {string} algorithm  RSA 算法（RSA-OAEP-256）
 * @property {string} mode       加密模式（envelope | session）
 */

/**
 * 加密请求体
 *
 * @typedef {Object} EncryptedRequest
 * @property {Object} _enc       加密信封
 * @property {string} _enc.alg           RSA 算法
 * @property {string} _enc.enc           AES 算法
 * @property {string} _enc.key_id        密钥 ID
 * @property {string} _enc.encrypted_key Base64 加密的 AES 密钥
 * @property {string} _enc.iv            Base64 IV
 * @property {string} _enc.tag           Base64 GCM 认证标签
 * @property {string} _enc.ciphertext    Base64 密文
 */

/**
 * 加密响应体
 *
 * @typedef {Object} EncryptedResponse
 * @property {Object} _enc       加密信封
 * @property {string} _enc.enc        AES 算法
 * @property {string} _enc.iv         Base64 IV
 * @property {string} _enc.tag        Base64 GCM 认证标签
 * @property {string} _enc.ciphertext Base64 密文
 */

class ApiCrypto {
    constructor() {
        /**
         * 缓存的公钥信息（按 keyId 索引）
         * @type {Map<string, PublicKeyInfo>}
         */
        this.keyCache = new Map();

        /**
         * 缓存的 RSA 公钥（CryptoKey 对象）
         * @type {Map<string, CryptoKey>}
         */
        this.rsaKeyCache = new Map();

        /**
         * 当前请求的 AES 密钥（用于解密响应）
         * @type {CryptoKey|null}
         */
        this.currentAesKey = null;
    }

    // ================================================================
    // 公钥管理
    // ================================================================

    /**
     * 从服务端获取公钥信息
     *
     * @param {string} url  公钥端点 URL（默认 /api/encrypt/public-key）
     * @returns {Promise<PublicKeyInfo>}
     * @throws {Error} 请求失败或响应异常时抛出
     */
    async fetchPublicKey(url = '/api/encrypt/public-key') {
        const resp = await fetch(url);
        if (!resp.ok) {
            throw new Error(`Failed to fetch public key: HTTP ${resp.status}`);
        }

        const body = await resp.json();
        if ((body.code || 0) !== 0) {
            throw new Error(`Failed to fetch public key: ${body.msg || 'unknown error'}`);
        }

        const keyInfo = body.data;
        if (!keyInfo || !keyInfo.public_key || !keyInfo.key_id) {
            throw new Error('Invalid public key response: missing required fields');
        }

        // 缓存
        this.keyCache.set(keyInfo.key_id, keyInfo);
        return keyInfo;
    }

    /**
     * 导入 PEM 格式 RSA 公钥为 Web Crypto CryptoKey
     *
     * @param {string} pem  PEM 格式公钥
     * @param {string} keyId 密钥 ID（用于缓存）
     * @returns {Promise<CryptoKey>}
     */
    async importPublicKey(pem, keyId) {
        const cached = this.rsaKeyCache.get(keyId);
        if (cached) return cached;

        const key = await this._pemToCryptoKey(pem);
        this.rsaKeyCache.set(keyId, key);
        return key;
    }

    // ================================================================
    // 请求加密（客户端 → 服务端）
    // ================================================================

    /**
     * 信封加密请求体
     *
     * 流程：
     *   1. 生成随机 AES-256 密钥 + 12 字节 IV
     *   2. AES-256-GCM 加密 JSON 字符串
     *   3. RSA-OAEP-256 加密 AES 密钥
     *   4. 组装加密信封
     *
     * @param {Object} payload     待加密的业务数据
     * @param {string} publicKeyPem PEM 格式 RSA 公钥
     * @param {string} keyId        密钥版本 ID
     * @returns {Promise<EncryptedRequest>}
     */
    async encryptRequest(payload, publicKeyPem, keyId) {
        // 1. 导入 RSA 公钥
        const rsaKey = await this.importPublicKey(publicKeyPem, keyId);

        // 2. 生成 AES-256 密钥
        const aesKey = await crypto.subtle.generateKey(
            { name: 'AES-GCM', length: 256 },
            true,
            ['encrypt', 'decrypt']
        );

        // 3. 生成随机 IV（12 字节）
        const iv = crypto.getRandomValues(new Uint8Array(12));

        // 4. AES-256-GCM 加密 payload
        const plaintext = new TextEncoder().encode(JSON.stringify(payload));
        const encrypted = await crypto.subtle.encrypt(
            { name: 'AES-GCM', iv },
            aesKey,
            plaintext
        );

        // GCM 输出 = ciphertext + 16-byte tag（拼接在末尾）
        const ciphertext = new Uint8Array(encrypted, 0, encrypted.byteLength - 16);
        const tag = new Uint8Array(encrypted, encrypted.byteLength - 16, 16);

        // 5. RSA-OAEP-256 加密 AES 密钥
        const rawAesKey = await crypto.subtle.exportKey('raw', aesKey);
        const encryptedKey = await crypto.subtle.encrypt(
            { name: 'RSA-OAEP' },
            rsaKey,
            rawAesKey
        );

        // 6. 暂存 AES 密钥（用于解密响应）
        this.currentAesKey = aesKey;

        // 7. 组装信封
        return {
            _enc: {
                alg: 'RSA-OAEP-256',
                enc: 'A256GCM',
                key_id: keyId,
                encrypted_key: this._arrayBufferToBase64(encryptedKey),
                iv: this._arrayBufferToBase64(iv.buffer),
                tag: this._arrayBufferToBase64(tag.buffer),
                ciphertext: this._arrayBufferToBase64(ciphertext.buffer),
            }
        };
    }

    // ================================================================
    // 响应解密（服务端 → 客户端）
    // ================================================================

    /**
     * 解密服务端响应
     *
     * 使用与请求相同的 AES 密钥解密响应信封。
     *
     * @param {Object} responseBody 加密的响应 JSON
     * @param {CryptoKey} [aesKey]   AES 密钥（默认使用上次 encryptRequest 的密钥）
     * @returns {Promise<Object>}   解密后的业务数据
     * @throws {Error} 响应未加密或解密失败时抛出
     */
    async decryptResponse(responseBody, aesKey = null) {
        const key = aesKey || this.currentAesKey;
        if (!key) {
            throw new Error('No AES key available for response decryption');
        }

        // 检查响应是否包含加密信封
        if (!responseBody || !responseBody._enc) {
            // 响应未加密，直接返回
            return responseBody;
        }

        const enc = responseBody._enc;

        // 验证必需字段
        if (!enc.ciphertext || !enc.iv || !enc.tag) {
            throw new Error('Invalid encrypted response: missing required fields');
        }

        // 构建 AES-GCM 输入（ciphertext + tag 拼接）
        const ciphertext = this._base64ToArrayBuffer(enc.ciphertext);
        const tag = this._base64ToArrayBuffer(enc.tag);
        const iv = this._base64ToUint8Array(enc.iv);

        // 拼接 ciphertext + tag
        const combined = new Uint8Array(ciphertext.byteLength + tag.byteLength);
        combined.set(new Uint8Array(ciphertext), 0);
        combined.set(new Uint8Array(tag), ciphertext.byteLength);

        // AES-GCM 解密（自动验证 tag）
        try {
            const decrypted = await crypto.subtle.decrypt(
                { name: 'AES-GCM', iv },
                key,
                combined
            );

            const plaintext = new TextDecoder().decode(decrypted);
            return JSON.parse(plaintext);
        } catch (err) {
            throw new Error(`Response decryption failed: ${err.message}`);
        }
    }

    // ================================================================
    // 全流程封装
    // ================================================================

    /**
     * 一键加密请求 + 解密响应
     *
     * 封装完整的加密通信流程，简化调用。
     *
     * const api = new ApiCrypto();
     * const result = await api.fetch('/api/v1/transfer', {
     *     amount: 100,
     *     currency: 'CNY'
     * });
     *
     * @param {string} url        API 端点
     * @param {Object} payload    业务数据
     * @param {Object} [options]  fetch 选项（method/headers）
     * @returns {Promise<Object>} 服务端解密后的响应
     */
    async fetch(url, payload, options = {}) {
        // 1. 获取公钥（如果未缓存）
        if (this.keyCache.size === 0) {
            await this.fetchPublicKey();
        }

        // 使用第一个缓存的公钥
        const [firstKeyId] = this.keyCache.keys();
        const keyInfo = this.keyCache.get(firstKeyId);

        // 2. 加密请求
        const encrypted = await this.encryptRequest(payload, keyInfo.public_key, keyInfo.key_id);

        // 3. 发送请求
        const mergedOptions = {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            ...options,
            body: JSON.stringify(encrypted),
        };

        const resp = await fetch(url, mergedOptions);
        if (!resp.ok) {
            // 非 2xx 响应可能是错误响应（不加密），直接返回
            const errorBody = await resp.json().catch(() => null);
            throw new Error(
                `API request failed: HTTP ${resp.status}` +
                (errorBody ? ` - ${errorBody.msg || ''}` : '')
            );
        }

        const body = await resp.json();

        // 4. 解密响应
        try {
            return await this.decryptResponse(body);
        } catch (err) {
            // 如果解密失败，可能是未加密的响应
            return body;
        }
    }

    /**
     * 清除所有缓存（密钥轮换时调用）
     */
    clearCache() {
        this.keyCache.clear();
        this.rsaKeyCache.clear();
        this.currentAesKey = null;
    }

    // ================================================================
    // 内部工具方法
    // ================================================================

    /**
     * PEM 公钥 → Web Crypto CryptoKey
     *
     * @param {string} pem PEM 格式公钥
     * @returns {Promise<CryptoKey>}
     */
    async _pemToCryptoKey(pem) {
        // 去除 PEM 头尾标记和换行符
        const b64 = pem
            .replace(/-----BEGIN PUBLIC KEY-----/, '')
            .replace(/-----END PUBLIC KEY-----/, '')
            .replace(/\s+/g, '');

        const binary = this._base64ToArrayBuffer(b64);

        return await crypto.subtle.importKey(
            'spki',
            binary,
            { name: 'RSA-OAEP', hash: 'SHA-256' },
            false,
            ['encrypt']
        );
    }

    /**
     * Base64 → ArrayBuffer
     */
    _base64ToArrayBuffer(b64) {
        const binary = atob(b64);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes.buffer;
    }

    /**
     * ArrayBuffer → Base64
     */
    _arrayBufferToBase64(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary);
    }

    /**
     * Base64 → Uint8Array
     */
    _base64ToUint8Array(b64) {
        const buffer = this._base64ToArrayBuffer(b64);
        return new Uint8Array(buffer);
    }
}

// 导出
export { ApiCrypto };
export default ApiCrypto;
