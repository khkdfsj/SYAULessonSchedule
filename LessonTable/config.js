// 系统信息
export const SYSTEM_INFO = uni.getSystemInfoSync()


// 主机地址
export const HOST = 'https://syauinfo.syau.edu.cn/';


// api服务器
export const API_HOST = SYSTEM_INFO.uniPlatform === 'web' ? '' : HOST;


// 本地 H5 开发通过 Vite 的 /h5api 代理访问接口；正式打包后直接请求当前域名下的 /LessonSchedule。
// 这样保留本地调试能力，同时避免外网 bm 服务器出现并不存在的 /h5api 路径。
export const API_PROXY = SYSTEM_INFO.uniPlatform === 'web' && import.meta.env.DEV ? '/h5api' : ''
