import { requestRaw } from '@/utils/http.js'
import { buildAuthPayload } from '@/utils/auth.js'

const COURSE_COMMENT_API_URL = '/LessonSchedule/courseCommentApi.php'

export function getCourseCommentList(payload) {
	return requestRaw({
		url: COURSE_COMMENT_API_URL,
		data: buildAuthPayload({
			action: 'comment_list',
			...payload
		})
	})
}

export function createCourseComment(payload) {
	return requestRaw({
		url: COURSE_COMMENT_API_URL,
		data: buildAuthPayload({
			action: 'comment_create',
			...payload
		})
	})
}

export function deleteCourseComment(commentId) {
	return requestRaw({
		url: COURSE_COMMENT_API_URL,
		data: buildAuthPayload({
			action: 'comment_delete',
			comment_id: commentId
		})
	})
}

export function toggleCourseCommentLike(commentId) {
	return requestRaw({
		url: COURSE_COMMENT_API_URL,
		data: buildAuthPayload({
			action: 'like_toggle',
			comment_id: commentId
		})
	})
}

export function getAdminCourseCommentThreads(options = {}) {
	return requestRaw({
		url: COURSE_COMMENT_API_URL,
		data: buildAuthPayload({
			action: 'admin_thread_list',
			page: options.page || 1,
			page_size: options.pageSize || 20,
			keyword: options.keyword || ''
		})
	})
}

export function adminDeleteCourseComment(commentId) {
	return requestRaw({
		url: COURSE_COMMENT_API_URL,
		data: buildAuthPayload({
			action: 'admin_delete',
			comment_id: commentId
		})
	})
}
