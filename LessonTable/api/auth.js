import { requestRaw } from '@/utils/http.js'

const LOGIN_API_URL = '/LessonSchedule/loginApi.php'

export function loginWithStudentProfile(payload) {
	return requestRaw({
		url: LOGIN_API_URL,
		data: payload
	})
}
