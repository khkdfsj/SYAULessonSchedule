import { packApiUrl } from '@/utils/common.js'
import { getAuthSession } from '@/utils/auth.js'

const COURSE_GROUP_API_URL = '/LessonSchedule/courseGroupApi.php'

/**
 * 调用课表侧课程群接口。
 * 用户身份只取已签名的课表认证信息，业务接口不会信任页面单独传入的学号。
 */
export function requestCourseGroup(action, course, extra = {}) {
	const session = getAuthSession()
	if (!session?.userId || !session?.authExp || !session?.authSig) {
		return Promise.reject({
			code: 401,
			msg: '身份认证已失效，请重新认证'
		})
	}

	const payload = {
		action,
		user_id: session.userId,
		auth_exp: session.authExp,
		auth_sig: session.authSig,
		courseNumber: `${course?.num || ''}`.trim(),
		courseOrder: `${course?.courseOrder || ''}`.trim(),
		teacherId: `${course?.teacherUserID || ''}`.trim(),
		planNumber: `${course?.planNumber || ''}`.trim(),
		...extra
	}

	return new Promise((resolve, reject) => {
		uni.request({
			url: packApiUrl(COURSE_GROUP_API_URL),
			method: 'POST',
			data: payload,
			header: {
				'content-type': 'application/json'
			},
			success: (response) => {
				const data = response?.data && typeof response.data === 'object'
					? response.data
					: { code: response?.statusCode || 502, msg: '课程群接口返回格式异常' }
				if (Number(response?.statusCode) >= 500 && !data.code) {
					data.code = response.statusCode
				}
				resolve(data)
			},
			fail: (error) => reject({
				code: 502,
				msg: error?.errMsg || '课程群接口暂时无法访问'
			})
		})
	})
}
