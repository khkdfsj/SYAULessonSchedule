import {
	request
} from "@/utils/request.js"

export function GetCourseInfo(data={}) {
	return request({
		data,
		url: "/LessonSchedule/curlGetSyauInfo.php"
	})
}