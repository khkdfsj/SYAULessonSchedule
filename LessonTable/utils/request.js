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
					resolve(res.data.data.courseInfo)
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