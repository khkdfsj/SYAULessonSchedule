import {
	packApiUrl
} from "./common.js"
export function request(config = {}) {
	let {
		url,
		data = {},
		method = "POST",
		header = {
			'content-type': 'application/json'
		}
	} = config

	url = packApiUrl(url);

	return new Promise((resolve, reject) => {
		uni.request({
			url,
			data,
			method,
			header,
			success: res => {
				if (res.data.code == 200) {
					// 返回完整响应体 {code, msg, data:{courseInfo, semesterStartDate, semesterMark, appVersion, ...}}
					// 之前只返回 data.courseInfo，导致在线分支拿不到服务端开学日期/学期标记/版本号
					resolve(res.data)
				} else {
					uni.showToast({
						title: res.data.msg,
						icon: "none"
					})
					reject(res.data)
				}

			},
			fail: err => {
				reject(err)
			}
		})
	})
}