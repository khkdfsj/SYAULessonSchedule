import { requestRaw } from '@/utils/http.js'
import { buildAuthPayload } from '@/utils/auth.js'

const FEEDBACK_API_URL = '/LessonSchedule/feedbackApi.php'

export function getFeedbackSession() {
	return requestRaw({
		url: FEEDBACK_API_URL,
		data: buildAuthPayload({
			action: 'session'
		})
	})
}

export function startAdminDebug(targetUserId) {
	return requestRaw({
		url: FEEDBACK_API_URL,
		data: buildAuthPayload({
			action: 'admin_debug_start',
			target_user_id: targetUserId
		})
	})
}

export function getAdminMemberList() {
	return requestRaw({
		url: FEEDBACK_API_URL,
		data: buildAuthPayload({
			action: 'admin_member_list'
		})
	})
}

export function updateAdminProfile(displayName) {
	return requestRaw({
		url: FEEDBACK_API_URL,
		data: buildAuthPayload({
			action: 'admin_profile_update',
			display_name: displayName
		})
	})
}

export function updateAdminNotification(notificationEnabled) {
	return requestRaw({
		url: FEEDBACK_API_URL,
		data: buildAuthPayload({
			action: 'admin_notification_update',
			notification_enabled: notificationEnabled
		})
	})
}

export function addAdminMember(payload) {
	return requestRaw({
		url: FEEDBACK_API_URL,
		data: buildAuthPayload({
			action: 'admin_member_add',
			...payload
		})
	})
}

export function updateAdminMember(payload) {
	return requestRaw({
		url: FEEDBACK_API_URL,
		data: buildAuthPayload({
			action: 'admin_member_update',
			...payload
		})
	})
}

export function removeAdminMember(targetUserId) {
	return requestRaw({
		url: FEEDBACK_API_URL,
		data: buildAuthPayload({
			action: 'admin_member_remove',
			target_user_id: targetUserId
		})
	})
}

export function getFeedbackThreadList(scope, options = {}) {
	return requestRaw({
		url: FEEDBACK_API_URL,
		data: buildAuthPayload({
			action: 'thread_list',
			scope,
			page: options.page || 1,
			page_size: options.pageSize || 20
		})
	})
}

export function getFeedbackThreadDetail(threadId) {
	return requestRaw({
		url: FEEDBACK_API_URL,
		data: buildAuthPayload({
			action: 'thread_detail',
			thread_id: threadId
		})
	})
}

export function createFeedbackThread(payload) {
	return requestRaw({
		url: FEEDBACK_API_URL,
		data: buildAuthPayload({
			action: 'thread_create',
			...payload
		})
	})
}

export function createFeedbackReply(payload) {
	return requestRaw({
		url: FEEDBACK_API_URL,
		data: buildAuthPayload({
			action: 'reply_create',
			...payload
		})
	})
}

export function toggleFeedbackLike(threadId) {
	return requestRaw({
		url: FEEDBACK_API_URL,
		data: buildAuthPayload({
			action: 'like_toggle',
			thread_id: threadId
		})
	})
}

export function adminUpdateFeedback(payload) {
	return requestRaw({
		url: FEEDBACK_API_URL,
		data: buildAuthPayload({
			action: 'admin_update',
			...payload
		})
	})
}
