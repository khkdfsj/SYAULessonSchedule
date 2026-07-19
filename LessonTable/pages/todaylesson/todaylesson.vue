<template>
	<view class="today_lesson_header_bg">
		<view class="todayDate">{{ formattedDate }} {{ weekdayText }}</view>
		<view class="currentStatus">{{ dailySlogan }}</view>
	</view>
	
	<view class="content">
		<!-- 上午课程 -->
		<view class="time-section" v-if="morningCourses.length > 0">
			<view class="section-title">
				<text class="section-name">上午课程</text>
				<text class="section-time">8:00-12:00</text>
			</view>
			<view class="course-list">
				<view class="course-item" v-for="course in morningCourses" :key="course.id">
					<view class="course-left">
						<text class="course-time">{{ formatCourseTime(course) }}</text>
					</view>
					<view class="course-right">
						<view class="course-name">{{ course.name }}</view>
						<view class="course-details">
							<text class="course-place">@{{ course.address }}</text>
							<text class="course-teacher">{{ course.teacher }}</text>
						</view>
					</view>
				</view>
			</view>
		</view>
		
		<!-- 下午课程 -->
		<view class="time-section" v-if="afternoonCourses.length > 0">
			<view class="section-title">
				<text class="section-name">下午课程</text>
				<text class="section-time">14:00-17:40</text>
			</view>
			<view class="course-list">
				<view class="course-item" v-for="course in afternoonCourses" :key="course.id">
					<view class="course-left">
						<text class="course-time">{{ formatCourseTime(course) }}</text>
					</view>
					<view class="course-right">
						<view class="course-name">{{ course.name }}</view>
						<view class="course-details">
							<text class="course-place">@{{ course.address }}</text>
							<text class="course-teacher">{{ course.teacher }}</text>
						</view>
					</view>
				</view>
			</view>
		</view>
		
		<!-- 晚上课程 -->
		<view class="time-section" v-if="eveningCourses.length > 0">
			<view class="section-title">
				<text class="section-name">晚上课程</text>
				<text class="section-time">19:00-22:40</text>
			</view>
			<view class="course-list">
				<view class="course-item" v-for="course in eveningCourses" :key="course.id">
					<view class="course-left">
						<text class="course-time">{{ formatCourseTime(course) }}</text>
					</view>
					<view class="course-right">
						<view class="course-name">{{ course.name }}</view>
						<view class="course-details">
							<text class="course-place">@{{ course.address }}</text>
							<text class="course-teacher">{{ course.teacher }}</text>
						</view>
					</view>
				</view>
			</view>
		</view>
		
		<!-- 没有课程提示 -->
		<view class="no-course" v-if="morningCourses.length === 0 && afternoonCourses.length === 0 && eveningCourses.length === 0">
			<image class="no-course-img" src="https://cdn.jsdelivr.net/gh/bestColour/images@main/uPic/noCourse.png" mode="aspectFit"></image>
			<view class="no-course-text">今日无课，尽情享受自由时光</view>
			<view class="no-course-tip">珍惜这难得的闲暇时光吧！</view>
		</view>
		
		<!-- 当前周数显示 -->
		<view class="week-info">
			<text class="week-text">第{{ currentWeek }}周</text>
			<text class="today-course-count">今日共{{ totalCourses }}节课</text>
		</view>
	</view>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue';

const alertDialog = ref(null);
const dailySlogan = ref('');
const morningCourses = ref([]);
const afternoonCourses = ref([]);
const eveningCourses = ref([]);
const currentWeek = ref(1);
const totalCourses = ref(0);

// 10条不同口号数组
const slogans = [
	"学习使我快乐充实！",
	"知识就是力量源泉！",
	"今天也要加油努力！",
	"每一节课都是收获！",
	"进步从此刻开始！",
	"用心聆听每一节课！",
	"智慧在课堂中闪光！",
	"勤奋是最好的天赋！",
	"今天的努力成就明天！",
	"享受学习的每一刻！"
];

// 格式化日期
const formattedDate = computed(() => {
	const today = new Date();
	const month = today.getMonth() + 1;
	const date = today.getDate();
	return `${month}月${date}日`;
});

// 获取星期几文本
const weekdayText = computed(() => {
	const weekdays = ['日', '一', '二', '三', '四', '五', '六'];
	const today = new Date();
	const weekday = today.getDay();
	return `星期${weekdays[weekday]}`;
});

// 随机选择一条口号
const getRandomSlogan = () => {
	const randomIndex = Math.floor(Math.random() * slogans.length);
	return slogans[randomIndex];
};

// 根据上课节次判断时间段
const getTimePeriod = (section) => {
	if (section >= 1 && section <= 4) {
		return 'morning'; // 上午
	} else if (section >= 5 && section <= 8) {
		return 'afternoon'; // 下午
	} else {
		return 'evening'; // 晚上
	}
};

// 格式化课程时间
const formatCourseTime = (course) => {
	const section = parseInt(course.section);
	const sectionCount = parseInt(course.sectionCount);
	
	// 获取当前时间表
	const today = new Date();
	const month = today.getMonth() + 1;
	let scheduleTime;
	
	if (month >= 5 && month <= 9) {
		// 夏季作息
		scheduleTime = [
			["8:00", "8:45"],
			["8:55", "9:40"],
			["10:00", "10:45"],
			["10:55", "11:40"],
			["14:00", "14:45"],
			["14:55", "15:40"],
			["16:00", "16:45"],
			["16:55", "17:40"],
			["19:00", "19:45"],
			["19:55", "20:40"],
			["21:00", "21:45"],
			["21:55", "22:40"]
		];
	} else {
		// 冬季作息
		scheduleTime = [
			["8:00", "8:45"],
			["8:55", "9:40"],
			["10:00", "10:45"],
			["10:55", "11:40"],
			["13:30", "14:15"],
			["14:25", "15:10"],
			["15:30", "16:15"],
			["16:25", "17:10"],
			["18:30", "19:15"],
			["19:25", "20:10"],
			["20:30", "21:15"],
			["21:25", "22:10"]
		];
	}
	
	// 计算开始和结束时间
	const startIndex = section - 1;
	const endIndex = section + sectionCount - 2;
	
	if (scheduleTime[startIndex] && scheduleTime[endIndex]) {
		const startTime = scheduleTime[startIndex][0];
		const endTime = scheduleTime[endIndex][1];
		return `${startTime}-${endTime}`;
	}
	
	return `第${section}-${section + sectionCount - 1}节`;
};

// 获取今天的课程
const getTodayCourses = () => {
	// 从本地存储获取课表数据
	const scheduleData = uni.getStorageSync('ScheduleData');
	const settings = uni.getStorageSync('scheduleSettings');
	
	if (!scheduleData || !scheduleData.courseList) {
		console.log('没有课表数据');
		return;
	}
	
	// 获取当前周数
	if (settings && settings.currentWeek) {
		currentWeek.value = settings.currentWeek;
	}
	
	// 获取今天是星期几（1-7，对应周一至周日）
	const today = new Date();
	let weekday = today.getDay(); // 0-6，0表示周日
	
	// 转换为课表中的week格式（1-7，周一为1）
	let courseWeekday;
	if (weekday === 0) {
		courseWeekday = 7; // 周日
	} else {
		courseWeekday = weekday; // 周一至周六
	}
	
	console.log('今天是星期', courseWeekday, '第', currentWeek.value, '周');
	
	// 筛选今天的课程
	const allCourses = scheduleData.courseList || [];
	const todayCourses = [];
	
	allCourses.forEach(course => {
		// 检查是否在今天上课
		if (parseInt(course.week) === courseWeekday) {
			// 检查是否在当前周上课
			if (course.weeks && course.weeks.includes(currentWeek.value)) {
				todayCourses.push(course);
			}
		}
	});
	
	console.log('今天共有', todayCourses.length, '节课');
	
	// 按上课节次排序
	todayCourses.sort((a, b) => parseInt(a.section) - parseInt(b.section));
	
	// 清空之前的课程
	morningCourses.value = [];
	afternoonCourses.value = [];
	eveningCourses.value = [];
	
	// 按时间段分组
	todayCourses.forEach(course => {
		const section = parseInt(course.section);
		const period = getTimePeriod(section);
		
		switch (period) {
			case 'morning':
				morningCourses.value.push(course);
				break;
			case 'afternoon':
				afternoonCourses.value.push(course);
				break;
			case 'evening':
				eveningCourses.value.push(course);
				break;
		}
	});
	
	// 计算总课程数
	totalCourses.value = todayCourses.length;
	
	// 更新口号
	updateSlogan();
};

// 根据课程情况更新口号
const updateSlogan = () => {
	const total = totalCourses.value;
	
	if (total === 0) {
		// 没有课程
		dailySlogan.value = "自由安排放松日";
	} else if (total <= 2) {
		// 课程较少
		dailySlogan.value = "轻松愉快的一天";
	} else if (total <= 4) {
		// 课程适中
		dailySlogan.value = "充实有序的学习日";
	} else {
		// 课程较多
		dailySlogan.value = "朕还能学";
	}
};

const open = () => {
	alertDialog.value?.open();
};

const close = () => {
	alertDialog.value?.close();
};

const goBack = () => {
	uni.reLaunch({
		url: '/pages/index/index'
	});
};

// 页面加载时获取今日课程
onMounted(() => {
	// 随机选择一条口号
	dailySlogan.value = getRandomSlogan();
	
	// 获取今日课程
	getTodayCourses();
	
	// 如果有课表数据，不显示弹窗
	const scheduleData = uni.getStorageSync('ScheduleData');
	if (scheduleData && scheduleData.courseList) {
		return;
	}
	
	// 如果没有课表数据，显示提示并返回
	uni.showModal({
		title: '提示',
		content: '请先加载课表数据',
		showCancel: false,
		success: (res) => {
			if (res.confirm) {
				goBack();
			}
		}
	});
});

const dialogConfirm = () => {
	close();
	goBack();
};

const dialogClose = () => {
	close();
	goBack();
};
</script>

<style lang="scss" scoped>
.today_lesson_header_bg {
	height: 60.462963vw;
	position: relative;
	background: url(/common/images/today_lesson_header_bg.png);
	background-size: cover;
	overflow: auto;
	z-index: 1;
	display: flex;
	flex-direction: column;
	justify-content: center;
	padding-left: 8.703704vw;

	.todayDate {
		font-weight: 400;
		font-size: 4.055556vw;
		color: rgba(255, 255, 255, 0.9);
		letter-spacing: .9px;
		margin-bottom: 2vw;
	}

	.currentStatus {
		font-weight: 700;
		font-size: 7.759259vw;
		color: #fff;
		letter-spacing: 0;
		text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
	}
}

.content {
	z-index: 10;
	position: relative;
	box-sizing: border-box;
	margin-top: -20.925926vw;
	padding: 6.481481vw 4.481481vw 25vw;
	background: #f8f9fa;
	min-height: calc(100vh - 39.53704vw);
	overflow: auto;
	border-radius: 5.555556vw 5.555556vw 0 0;
	
	.time-section {
		background: #fff;
		border-radius: 4vw;
		margin-bottom: 4vw;
		padding: 4vw;
		box-shadow: 0 2px 12px rgba(0, 0, 0, 0.05);
		
		.section-title {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 3vw;
			padding-bottom: 2vw;
			border-bottom: 1px solid #f0f0f0;
			
			.section-name {
				font-size: 4.5vw;
				font-weight: 600;
				color: #333;
			}
			
			.section-time {
				font-size: 3.5vw;
				color: #666;
			}
		}
		
		.course-list {
			.course-item {
				display: flex;
				margin-bottom: 4vw;
				padding-bottom: 4vw;
				border-bottom: 1px solid #f5f5f5;
				
				&:last-child {
					margin-bottom: 0;
					padding-bottom: 0;
					border-bottom: none;
				}
				
				.course-left {
					flex: 0 0 25vw;
					padding-right: 3vw;
					
					.course-time {
						font-size: 3.5vw;
						color: #666;
						display: block;
						margin-bottom: 1vw;
					}
				}
				
				.course-right {
					flex: 1;
					
					.course-name {
						font-size: 4.2vw;
						font-weight: 500;
						color: #333;
						margin-bottom: 2vw;
						line-height: 1.3;
					}
					
					.course-details {
						display: flex;
						flex-direction: column;
						gap: 1vw;
						
						.course-place {
							font-size: 3.5vw;
							color: #666;
						}
						
						.course-teacher {
							font-size: 3.5vw;
							color: #888;
						}
					}
				}
			}
		}
	}
	
	.no-course {
		text-align: center;
		padding: 10vw 0;
		
		.no-course-img {
			width: 40vw;
			height: 40vw;
			margin-bottom: 4vw;
			opacity: 0.8;
		}
		
		.no-course-text {
			font-size: 4.5vw;
			color: #666;
			margin-bottom: 2vw;
			font-weight: 500;
		}
		
		.no-course-tip {
			font-size: 3.8vw;
			color: #999;
		}
	}
	
	.week-info {
		display: flex;
		justify-content: space-between;
		align-items: center;
		background: #fff;
		border-radius: 4vw;
		padding: 3vw 4vw;
		margin-top: 4vw;
		
		.week-text {
			font-size: 3.8vw;
			color: #666;
			font-weight: 500;
		}
		
		.today-course-count {
			font-size: 3.8vw;
			color: #00aaff;
			font-weight: 500;
		}
	}
}

// 调整不同时间段的样式
.time-section:nth-child(1) .section-name {
	color: #ff6b6b;
}

.time-section:nth-child(2) .section-name {
	color: #4ecdc4;
}

.time-section:nth-child(3) .section-name {
	color: #45b7d1;
}
</style>
