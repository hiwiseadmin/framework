/**
 * WsLayer 类型声明文件
 * 用于为 ws-layer.js 提供代码提示（IntelliSense）
 * @version 1.0.0
 */

declare namespace WsLayerTypes {
    /** 弹层类型 */
    type LayerType = 0 | 1 | 2 | 3 | 4;

    /** 图标类型 */
    type IconType = -1 | 0 | 1 | 2 | 3 | 4 | 5 | 6 | 7
        | 'info' | 'success' | 'warning' | 'danger' | 'primary' | 'lock' | 'frown' | 'smile';

    /** 提示方向 */
    type TipsDirection = 1 | 2 | 3 | 4;

    /** 位置偏移 */
    type Offset = 'auto' | 't' | 'r' | 'b' | 'l' | 'lt' | 'lb' | 'rt' | 'rb' | 'c'
        | [string | number, string | number];

    /** 宽高配置 */
    type Area = 'auto' | string | [string | number, string | number];

    /** 按钮配置 */
    type Btn = string | string[] | false;

    /** 顶层窗口层级 */
    type TopLevel = number | string;

    /** 打开弹层的配置项 */
    interface LayerOptions {
        /** 框架名称 */
        frameName?: string;
        /** 弹层类型: 0-对话框, 1-页面层, 2-iframe层, 3-loading加载层, 4-tips提示层 */
        type?: LayerType;
        /** 标题 */
        title?: string | false;
        /** 内容，支持 HTML 和 url，iframe 类型支持 [url, 'auto|no'] */
        content?: string | [string, string?];
        /** 皮肤样式 */
        skin?: string;
        /** 宽高 [width, height] */
        area?: Area;
        /** 位置 */
        offset?: Offset;
        /** 图标类型 */
        icon?: IconType;
        /** 按钮 */
        btn?: Btn;
        /** 关闭按钮 */
        closeBtn?: boolean;
        /** 最小化按钮 */
        minBtn?: boolean;
        /** 最大化按钮 */
        maxBtn?: boolean;
        /** 遮罩 */
        shade?: boolean | number;
        /** 点击遮罩关闭 */
        shadeClose?: boolean;
        /** 自动关闭时间(毫秒) */
        time?: number;
        /** 弹层 ID */
        id?: string | number | null;
        /** 动画 */
        anim?: boolean;
        /** 退出动画 */
        isOutAnim?: boolean;
        /** 固定定位 */
        fixed?: boolean;
        /** 允许调整大小 */
        resize?: boolean;
        /** 允许滚动条 */
        scrollbar?: boolean;
        /** 最大宽度 */
        maxWidth?: number;
        /** 最大高度 */
        maxHeight?: number;
        /** 层级 */
        zIndex?: number;
        /** 只能显示一个弹层 */
        onlyOne?: boolean;
        /** 最小化堆叠 */
        minStack?: boolean;
        /** 允许拖拽 */
        move?: boolean;
        /** 拖拽类型 */
        moveType?: number;
        /** 允许拖出窗口 */
        moveOut?: boolean;
        /** 提示方向 1:上, 2:右, 3:下, 4:左 */
        tips?: TipsDirection;
        /** 提示层跟随元素 */
        follow?: string | Element | JQuery;
        /** 异步按钮 */
        btnAsync?: boolean;
        /** 允许多个提示 */
        tipsMore?: boolean;
        /** ESC 键关闭 */
        closeOnEsc?: boolean;
        /** Enter 键提交 */
        submitOnEnter?: boolean;
        /** 在输入框内也允许 Enter 提交 */
        submitOnEnterInInput?: boolean;
        /** 默认加载动画 */
        loadingAnimation?: string;
        /** 加载图标大小 */
        loadingIconSize?: string;
        /** 加载文字颜色 */
        loadingTextColor?: string;
        /** 自动更新 iframe 标题 */
        autoUpdateTitle?: boolean;
        /** 输入框类型 */
        formType?: string;
        /** 输入框占位符 */
        placeholder?: string;
        /** 输入框默认值 */
        value?: string;
        /** 标签页 */
        tab?: Array<{ title: string; content: string }>;
        /** 图片数据 */
        photos?: {
            data: Array<{ src: string; alt?: string }>;
            start?: number;
        };

        /** 创建成功回调 */
        success?: (layero: JQuery, index: number, instance?: LayerInstance) => void;
        /** 确定按钮回调 */
        yes?: (index: number, layero: JQuery, instance?: LayerInstance) => any;
        /** 取消按钮回调 */
        cancel?: (index: number, layero: JQuery, instance?: LayerInstance) => any;
        /** 关闭后回调 */
        end?: (exitType?: string) => void;
        /** 最大化回调 */
        full?: (index: number, layero: JQuery, instance?: LayerInstance) => void;
        /** 还原回调 */
        restore?: (index: number, layero: JQuery, instance?: LayerInstance) => void;
        /** 最小化回调 */
        minEnd?: (index: number, layero: JQuery, instance?: LayerInstance) => void;
        /** 拖拽结束回调 */
        moveEnd?: (layero: JQuery, instance?: LayerInstance) => void;
        /** 调整大小回调 */
        resizing?: (layero: JQuery, instance?: LayerInstance) => void;
        /** iframe 标题回调函数 */
        iframeTitle?: (title: string, index: number, layero: JQuery, instance?: LayerInstance) => string | false | void;
        /** iframe 加载完成回调 */
        iframeLoad?: (index: number, layero: JQuery, instance?: LayerInstance) => void;
        /** iframe 加载错误回调 */
        iframeLoadError?: (error: any, index: number, layero: JQuery, instance?: LayerInstance) => void;
        /** 附加按钮回调 (btn1, btn2 ...) */
        [key: string]: any;
    }

    /** 弹层实例 */
    interface LayerInstance {
        index: number;
        config: LayerOptions;
        isMinimized: boolean;
        isMaximized: boolean;
        originalStyle: Record<string, string>;
        photosData?: any;
        closeData?: any;
        close(exitType?: string): void;
        minimize(): void;
        maximize(): void;
        restore(): void;
        setTitle(title: string): void;
    }

    /** 顶层窗口链式调用对象 */
    interface TopChain {
        execute(method: string, args: any[]): any;
        open(options: LayerOptions): number;
        close(index: number, exitType?: string): void;
        closeAll(type?: number | string): void;
        closeLast(type?: number | string): void;
        min(index: number): void;
        max(index: number): void;
        restore(index: number): void;
        title(title: string, index: number): void;
        iframe(url: string, options?: LayerOptions | Function, yes?: Function, cancel?: Function): number;
        alert(content: string, options?: LayerOptions | Function, yes?: Function): number;
        confirm(content: string, options?: LayerOptions | Function, yes?: Function, cancel?: Function): number;
        msg(content: string, options?: LayerOptions | Function, end?: Function): number;
        load(icon?: number | LayerOptions, options?: LayerOptions): number;
        tips(content: string, follow: string | Element | JQuery, options?: LayerOptions): number;
        prompt(options: LayerOptions | Function, yes?: Function): number;
        tab(options: LayerOptions): number;
        photos(options: LayerOptions): number;
        toast(options: ToastOptions | string): string;
        getChildFrame(selector: string, index: number): JQuery;
        getInstances(): Record<number, LayerInstance>;
        setup(options: LayerOptions): void;
        setLang(lang: string): void;
        addMessages(lang: string, messages: Record<string, any>): void;
        setShared(key: string | Record<string, any>, value?: any): WsLayer;
        getShared(key?: string): any;
        hasShared(key: string): boolean;
        removeShared(key: string): WsLayer;
        clearShared(): WsLayer;
    }

    /** Toast 类型 */
    type ToastType = 'info' | 'success' | 'warning' | 'error' | 'danger';

    /** Toast 配置 */
    interface ToastOptions {
        content: string;
        type?: ToastType;
        time?: number;
        position?: string;
        end?: () => void;
    }
}

declare interface WsLayer {
    version: string;
    DEFAULT_CONFIG: WsLayerTypes.LayerOptions;
    index: number;
    sharedData: Record<string, any>;
    instances: Array<WsLayerTypes.LayerInstance | null>;
    minStackArr: any[];
    i18n: Record<string, any>;
    lang: string;

    init(): void;
    createContainer(): void;
    injectStyles(): void;

    /** 顶层窗口链式调用 */
    top(level?: WsLayerTypes.TopLevel): WsLayerTypes.TopChain;

    isInIframe(): boolean;
    hasLayerInTopWindow(): boolean;

    /** 打开弹层，返回弹层索引 */
    open(options: WsLayerTypes.LayerOptions): number;

    /** 关闭弹层 */
    close(index: number, exitType?: string): void;
    /** 关闭所有弹层 */
    closeAll(type?: number | string): void;
    /** 关闭最近打开的弹层 */
    closeLast(type?: number | string): void;

    /** 警告框 */
    alert(content: string, options?: WsLayerTypes.LayerOptions | Function, yes?: Function): number;
    /** 确认框 */
    confirm(content: string, options?: WsLayerTypes.LayerOptions | Function, yes?: Function, cancel?: Function): number;
    /** iframe 弹层 */
    iframe(url: string, options?: WsLayerTypes.LayerOptions | Function, yes?: Function, cancel?: Function): number;
    /** loading 弹层 */
    loading(options?: WsLayerTypes.LayerOptions): number;
    /** 消息提示 */
    msg(content: string, options?: WsLayerTypes.LayerOptions | Function, end?: Function): number;
    /** 加载层 */
    load(icon?: number | WsLayerTypes.LayerOptions, options?: WsLayerTypes.LayerOptions): number;
    /** 提示层 */
    tips(content: string, follow: string | Element | JQuery, options?: WsLayerTypes.LayerOptions): number;
    /** 输入框 */
    prompt(options: WsLayerTypes.LayerOptions | Function, yes?: Function): number;
    /** 输入框特殊处理 */
    handlePromptEvents($layer: JQuery): void;
    /** 标签页弹层 */
    tab(options: WsLayerTypes.LayerOptions): number;
    /** 图片浏览器 */
    photos(options: WsLayerTypes.LayerOptions): number;
    /** Toast 通知 */
    toast(options: WsLayerTypes.ToastOptions | string): string;

    /** 最小化弹层 */
    min(index: number): void;
    /** 最大化弹层 */
    max(index: number): void;
    /** 还原弹层 */
    restore(index: number): void;
    /** 动态设置标题 */
    title(title: string, index: number): void;

    /** 获取子 iframe 的 DOM */
    getChildFrame(selector: string, index: number): JQuery;

    getToastIconClass(type: string): string;
    getToastBgClass(type: string): string;
    getToastTitle(type: string): string;

    /** 获取 iframe 层的索引 */
    getFrameIndex(windowName: string): number;
    /** 获取当前 iframe 的父层索引 */
    getParentFrameIndex(): number;
    /** 关闭当前 iframe 所在的弹层 */
    closeFrame(data?: any): void;
    /** 从 iframe 内部调用父窗口的方法 */
    callParent(method: string, ...args: any[]): any;

    /** 国际化翻译 */
    $t(key: string, options?: Record<string, any>): string;
    /** 设置语言 */
    setLang(lang: string): void;
    /** 添加语言包 */
    addMessages(lang: string, messages: Record<string, any>): void;
    /** 设置全局配置 */
    config(options: WsLayerTypes.LayerOptions): void;
    /** 获取当前所有弹层 */
    getInstances(): Record<number, WsLayerTypes.LayerInstance>;

    /** 设置共享数据 */
    setShared(key: string | Record<string, any>, value?: any): WsLayer;
    /** 获取共享数据 */
    getShared(key?: string): any;
    /** 检查共享数据是否存在 */
    hasShared(key: string): boolean;
    /** 删除共享数据 */
    removeShared(key: string): WsLayer;
    /** 清空所有共享数据 */
    clearShared(): WsLayer;

    /** HTML 转义，防止 XSS 注入 */
    escapeHtml(str: string): string;
}

declare var WsLayer: WsLayer;
declare var wsLayer: WsLayer;
declare var Layer: WsLayer;

declare global {
    interface Window {
        WsLayer: WsLayer;
        wsLayer: WsLayer;
        Layer: WsLayer;
    }
}
