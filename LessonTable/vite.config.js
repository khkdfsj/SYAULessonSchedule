import {
	defineConfig
} from 'vite'
import uni from '@dcloudio/vite-plugin-uni'
import AutoImport from 'unplugin-auto-import/vite'

export default defineConfig({
	plugins: [
		uni(),
		// 自动导入配置
		AutoImport({
			imports: [
				// 预设
				'vue',
				'uni-app'
			]
		})
	],
	server: {
	    host: "127.0.0.1", // 避免在部分环境下绑定到 ::1 (IPv6) 失败
	    port: 8080,        // 5173/5174 在部分环境会被拒绝，改用常见可用端口
	    strictPort: false, // 端口占用时自动顺延
	    proxy: {           // 为开发服务器配置自定义代理规则
	       // 带选项写法：http://localhost:5173/api/posts -> http://jsonplaceholder.typicode.com/posts
	      "/h5api": {
	        target: "https://debug.91nongye.cn/", // 目标接口
	        changeOrigin: true,            // 是否换源
	        rewrite: (path) => path.replace(/^\/h5api/, ""),
	      }
	    }
	  }
})
