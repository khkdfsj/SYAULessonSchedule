import { requestRaw } from '@/utils/http.js'
import { buildAuthPayload } from '@/utils/auth.js'

const SURVEY_API_URL = '/LessonSchedule/surveyApi.php'
const SURVEY_CLIENT_TOKEN_KEY = 'LessonSchedule.SurveyClientToken.v1'

/**
 * 问卷服务部署在外网，夜间（内网关闭）用户通常没有有效签名；
 * 因此额外带一个本地令牌用于匿名去重，保证夜间缓存时段也能正常填写。
 */
const getClientToken = () => {
	let token = uni.getStorageSync(SURVEY_CLIENT_TOKEN_KEY)
	if (typeof token !== 'string' || !/^[A-Za-z0-9_-]{8,48}$/.test(token)) {
		const seed = Math.random().toString(36).slice(2, 10) + Math.random().toString(36).slice(2, 10)
		token = 'd' + Date.now().toString(36) + seed
		uni.setStorageSync(SURVEY_CLIENT_TOKEN_KEY, token)
	}
	return token
}

const requestSurvey = (payload) => requestRaw({
	url: SURVEY_API_URL,
	data: {
		...buildAuthPayload(payload),
		client_token: getClientToken()
	}
})

export const getSurveyEntry = () => requestSurvey({ action: 'entry' })

export const getSurveyQuestions = (mode) => requestSurvey({ action: 'questions', mode })

export const submitSurvey = (mode, answers) => requestSurvey({ action: 'submit', mode, answers })

export const listSurveyRounds = () => requestSurvey({ action: 'adminList' })

export const saveSurveyRound = (payload) => requestSurvey({ action: 'adminSave', ...payload })

export const setSurveyRoundStatus = (id, status) => requestSurvey({ action: 'adminStatus', id, status })

export const getSurveyResults = (roundId) => requestSurvey({ action: 'adminResults', round_id: roundId })
