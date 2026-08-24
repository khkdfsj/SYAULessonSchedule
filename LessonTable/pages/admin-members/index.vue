<template>
	<view class="page">
		<view class="header">
			<view class="header-btn" @click="goBack"><uni-icons type="left" size="22" color="#111827"></uni-icons></view>
			<view class="header-title">管理员管理</view>
			<view class="header-space"></view>
		</view>

		<scroll-view scroll-y class="page-scroll">
			<view v-if="loading" class="state-row">正在加载管理员信息…</view>
			<view v-else-if="errorText" class="state-row warn">{{ errorText }}</view>
			<template v-else>
				<view class="action-bar">
					<view class="identity-summary">
						<view class="identity-name">{{ ownAdminLabel }}</view>
						<view class="identity-meta">{{ currentUserId }} · {{ isSuperAdmin ? '超级管理员' : '管理员' }}</view>
					</view>
					<view class="action-buttons">
						<view class="action-btn secondary" @click="openProfileDrawer">修改名称</view>
						<view v-if="isSuperAdmin" class="action-btn primary" @click="openAddDrawer">新增管理员</view>
					</view>
				</view>

				<view class="list-section">
					<view class="list-title-row">
						<view class="list-title">管理员成员</view>
						<view class="member-count">{{ members.length }} 人</view>
					</view>
					<view class="table-head">
						<text class="col-member">成员</text>
						<text class="col-role">权限</text>
						<text v-if="isSuperAdmin" class="col-action">操作</text>
					</view>
					<view v-for="item in members" :key="item.user_id" class="table-row">
						<view class="col-member member-cell">
							<view class="member-name">{{ adminLabel(item) }}</view>
							<view class="member-id">{{ item.user_id }}<text v-if="item.user_id === currentUserId"> · 我</text></view>
						</view>
						<view class="col-role">
							<view class="role-text" :class="{ super: item.is_super_admin }">{{ item.is_super_admin ? '超级管理员' : '管理员' }}</view>
						</view>
						<view v-if="isSuperAdmin" class="col-action row-actions">
							<view class="row-action" @click="toggleMemberRole(item)">{{ item.is_super_admin ? '降级' : '设为超管' }}</view>
							<view v-if="item.user_id !== currentUserId" class="row-action danger" @click="removeMember(item)">移除</view>
						</view>
					</view>
				</view>
			</template>
		</scroll-view>

		<uni-popup ref="profileDrawer" type="bottom" background-color="#fff">
			<view class="drawer">
				<view class="drawer-handle"></view>
				<view class="drawer-head">
					<view class="drawer-title">修改管理员名称</view>
					<view class="drawer-close" @click="closeProfileDrawer">取消</view>
				</view>
				<view class="field-label">管理员名称</view>
				<input v-model="ownDisplayName" class="text-input" maxlength="30" placeholder="不填写则显示为管理员" :disabled="savingProfile" />
				<view class="field-help">填写后，反馈回复将显示为“管理员•名称”。</view>
				<view class="drawer-submit" :class="{ disabled: savingProfile }" @click="saveOwnProfile">{{ savingProfile ? '正在保存…' : '保存名称' }}</view>
			</view>
		</uni-popup>

		<uni-popup ref="addDrawer" type="bottom" background-color="#fff">
			<view class="drawer">
				<view class="drawer-handle"></view>
				<view class="drawer-head">
					<view class="drawer-title">新增管理员</view>
					<view class="drawer-close" @click="closeAddDrawer">取消</view>
				</view>
				<view class="field-label">学号</view>
				<input v-model="newMember.userId" class="text-input" type="number" maxlength="12" placeholder="请输入学号" :disabled="addingMember" />
				<view class="field-label">管理员名称</view>
				<input v-model="newMember.displayName" class="text-input" maxlength="30" placeholder="选填，默认显示为管理员" :disabled="addingMember" />
				<view class="switch-row">
					<view>
						<view class="switch-title">设为超级管理员</view>
						<view class="switch-subtitle">允许管理管理员成员。</view>
					</view>
					<switch :checked="newMember.isSuperAdmin" color="#2563eb" @change="newMember.isSuperAdmin = $event.detail.value" />
				</view>
				<view class="drawer-submit" :class="{ disabled: addingMember }" @click="addMember">{{ addingMember ? '正在添加…' : '添加管理员' }}</view>
			</view>
		</uni-popup>
	</view>
</template>

<script setup>
import { computed, ref } from 'vue'
import { addAdminMember, getAdminMemberList, removeAdminMember, updateAdminMember, updateAdminProfile } from '@/api/feedback.js'
import { getErrorMessage } from '@/utils/http.js'

const loading = ref(true)
const errorText = ref('')
const currentUserId = ref('')
const isSuperAdmin = ref(false)
const ownDisplayName = ref('')
const members = ref([])
const savingProfile = ref(false)
const addingMember = ref(false)
const profileDrawer = ref(null)
const addDrawer = ref(null)
const newMember = ref({ userId: '', displayName: '', isSuperAdmin: false })

const ownAdminLabel = computed(() => ownDisplayName.value ? `管理员•${ownDisplayName.value}` : '管理员')
const adminLabel = (item) => item.display_name ? `管理员•${item.display_name}` : '管理员'
const goBack = () => uni.navigateBack()
const openProfileDrawer = () => profileDrawer.value?.open()
const openAddDrawer = () => addDrawer.value?.open()
const closeProfileDrawer = () => profileDrawer.value?.close()
const closeAddDrawer = () => addDrawer.value?.close()

const loadMembers = async () => {
	loading.value = true
	errorText.value = ''
	try {
		const response = await getAdminMemberList()
		members.value = response?.data?.list || []
		currentUserId.value = response?.data?.current_user_id || ''
		isSuperAdmin.value = response?.data?.is_super_admin === true
		ownDisplayName.value = response?.data?.admin_display_name || ''
	} catch (error) {
		errorText.value = getErrorMessage(error, '管理员信息加载失败')
	} finally {
		loading.value = false
	}
}

const saveOwnProfile = async () => {
	if (savingProfile.value) return
	savingProfile.value = true
	try {
		await updateAdminProfile(ownDisplayName.value.trim())
		profileDrawer.value?.close()
		await loadMembers()
		uni.showToast({ title: '名称已保存', icon: 'success' })
	} catch (error) {
		uni.showToast({ title: getErrorMessage(error, '保存失败'), icon: 'none' })
	} finally {
		savingProfile.value = false
	}
}

const addMember = async () => {
	if (addingMember.value) return
	const userId = newMember.value.userId.trim()
	if (!/^\d{8,12}$/.test(userId)) return uni.showToast({ title: '请输入正确的学号', icon: 'none' })
	addingMember.value = true
	try {
		await addAdminMember({ target_user_id: userId, display_name: newMember.value.displayName.trim(), is_super_admin: newMember.value.isSuperAdmin })
		newMember.value = { userId: '', displayName: '', isSuperAdmin: false }
		addDrawer.value?.close()
		await loadMembers()
		uni.showToast({ title: '管理员已添加', icon: 'success' })
	} catch (error) {
		uni.showToast({ title: getErrorMessage(error, '添加失败'), icon: 'none' })
	} finally {
		addingMember.value = false
	}
}

const toggleMemberRole = (item) => {
	const nextIsSuperAdmin = !item.is_super_admin
	uni.showModal({
		title: nextIsSuperAdmin ? '设为超级管理员' : '取消超级管理员',
		content: nextIsSuperAdmin ? `确认授予 ${item.user_id} 管理管理员成员的权限吗？` : `确认将 ${item.user_id} 调整为普通管理员吗？`,
		confirmText: '确认调整', cancelText: '取消',
		success: async (result) => {
			if (!result.confirm) return
			try {
				await updateAdminMember({ target_user_id: item.user_id, is_super_admin: nextIsSuperAdmin })
				await loadMembers()
				uni.showToast({ title: '权限已更新', icon: 'success' })
			} catch (error) { uni.showToast({ title: getErrorMessage(error, '调整失败'), icon: 'none' }) }
		}
	})
}

const removeMember = (item) => {
	uni.showModal({
		title: '移除管理员', content: `确认移除管理员 ${item.user_id} 吗？移除后将无法使用管理员功能，也不会再接收反馈提醒。`,
		confirmText: '确认移除', confirmColor: '#dc2626', cancelText: '取消',
		success: async (result) => {
			if (!result.confirm) return
			try {
				await removeAdminMember(item.user_id)
				await loadMembers()
				uni.showToast({ title: '已移除', icon: 'success' })
			} catch (error) { uni.showToast({ title: getErrorMessage(error, '移除失败'), icon: 'none' }) }
		}
	})
}

onShow(loadMembers)
</script>

<style lang="scss" scoped>
.page { min-height: 100vh; background: #f3f6fa; }
.header { height: calc(env(safe-area-inset-top) + 92rpx); padding: env(safe-area-inset-top) 24rpx 0; display: flex; align-items: center; justify-content: space-between; box-sizing: border-box; }
.header-btn, .header-space { width: 76rpx; height: 64rpx; display: flex; align-items: center; justify-content: center; }
.header-title { font-size: 34rpx; font-weight: 700; color: #111827; }
.page-scroll { height: calc(100vh - env(safe-area-inset-top) - 92rpx); padding: 12rpx 24rpx calc(env(safe-area-inset-bottom) + 36rpx); box-sizing: border-box; }
.state-row, .action-bar, .list-section { background: #fff; }
.state-row { padding: 30rpx; border-radius: 22rpx; color: #64748b; font-size: 25rpx; }
.state-row.warn { color: #b45309; }
.action-bar { padding: 26rpx; border-radius: 22rpx; display: flex; align-items: center; justify-content: space-between; gap: 20rpx; }
.identity-summary { min-width: 0; }
.identity-name { font-size: 28rpx; font-weight: 700; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.identity-meta { margin-top: 7rpx; font-size: 21rpx; color: #64748b; }
.action-buttons { flex: none; display: flex; gap: 12rpx; }
.action-btn { height: 58rpx; padding: 0 18rpx; border-radius: 14rpx; display: flex; align-items: center; justify-content: center; font-size: 22rpx; font-weight: 700; }
.action-btn.secondary { background: #f1f5f9; color: #334155; }
.action-btn.primary { background: #2563eb; color: #fff; }
.list-section { margin-top: 20rpx; border-radius: 22rpx; overflow: hidden; }
.list-title-row { height: 82rpx; padding: 0 24rpx; display: flex; align-items: center; justify-content: space-between; border-bottom: 1rpx solid #e2e8f0; }
.list-title { font-size: 28rpx; font-weight: 700; color: #0f172a; }
.member-count { font-size: 22rpx; color: #64748b; }
.table-head, .table-row { padding: 0 24rpx; display: flex; align-items: center; gap: 16rpx; }
.table-head { height: 64rpx; background: #f8fafc; border-bottom: 1rpx solid #e2e8f0; color: #64748b; font-size: 20rpx; }
.table-row { min-height: 96rpx; border-bottom: 1rpx solid #edf1f5; }
.table-row:last-child { border-bottom: none; }
.col-member { min-width: 0; flex: 1; }
.col-role { width: 120rpx; flex: none; }
.col-action { width: 190rpx; flex: none; text-align: right; }
.member-name { font-size: 25rpx; font-weight: 650; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.member-id { margin-top: 6rpx; font-size: 20rpx; color: #64748b; }
.role-text { font-size: 21rpx; color: #475569; }
.role-text.super { color: #2563eb; font-weight: 700; }
.row-actions { display: flex; justify-content: flex-end; gap: 16rpx; }
.row-action { font-size: 20rpx; font-weight: 650; color: #2563eb; }
.row-action.danger { color: #dc2626; }
.drawer { padding: 12rpx 28rpx calc(env(safe-area-inset-bottom) + 28rpx); border-radius: 28rpx 28rpx 0 0; }
.drawer-handle { width: 72rpx; height: 8rpx; margin: 0 auto 20rpx; border-radius: 999rpx; background: #d7dee8; }
.drawer-head { display: flex; align-items: center; justify-content: space-between; }
.drawer-title { font-size: 31rpx; font-weight: 700; color: #0f172a; }
.drawer-close { font-size: 24rpx; color: #64748b; }
.field-label { margin-top: 24rpx; font-size: 24rpx; font-weight: 700; color: #334155; }
.text-input { height: 84rpx; margin-top: 12rpx; padding: 0 20rpx; box-sizing: border-box; border: 1rpx solid #cbd5e1; border-radius: 16rpx; background: #f8fafc; color: #0f172a; font-size: 27rpx; }
.field-help { margin-top: 10rpx; font-size: 21rpx; color: #94a3b8; }
.switch-row { margin-top: 22rpx; padding-top: 20rpx; display: flex; align-items: center; justify-content: space-between; border-top: 1rpx solid #e2e8f0; }
.switch-title { font-size: 25rpx; font-weight: 700; color: #334155; }
.switch-subtitle { margin-top: 6rpx; font-size: 21rpx; color: #64748b; }
.drawer-submit { height: 84rpx; margin-top: 26rpx; border-radius: 18rpx; display: flex; align-items: center; justify-content: center; background: #2563eb; color: #fff; font-size: 27rpx; font-weight: 700; }
.drawer-submit.disabled { opacity: 0.55; pointer-events: none; }
</style>
