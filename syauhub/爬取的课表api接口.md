个人信息页面：http://10.2.0.47/student/rollManagement/rollInfo/index
需要提取个人信息的html：
```html
<div class="col-xs-8"><h4 class="header smaller lighter grey"><img src="/assets/images/identity.png"style="position:relative;top:-6px;">学籍信息<span class="right_top_oper"><button id="loading-btn"class="btn btn-purple btn-xs btn-round"data-loading-text="正在跳转..."onclick="xiugai();return false;"><i class="ace-icon fa fa-pencil-square-o bigger-120"></i>修改信息</button></span></h4><div><div class="self profile-user-info profile-user-info-striped setLabelWidth"><div class="profile-info-row"><div class="profile-info-name">学号</div><div class="profile-info-value"style="width:34%">2022140101</div><div class="profile-info-name">姓名</div><div class="profile-info-value">朱玄堇</div></div><div class="profile-info-row"><div class="profile-info-name">姓名拼音</div><div class="profile-info-value"></div><div class="profile-info-name">英文姓名</div><div class="profile-info-value"></div></div><div class="profile-info-row"><div class="profile-info-name">证件号码</div><div class="profile-info-value"id="sfzhDiv"onclick="urp.showPlaintext('500234200311016659', this);"style="cursor: pointer; color: #4007a2;">******</div><div class="profile-info-name">年级</div><div class="profile-info-value">2022级</div></div><div class="profile-info-row"><div class="profile-info-name">院系</div><div class="profile-info-value">园艺</div><div class="profile-info-name">专业</div><div class="profile-info-value">园艺</div></div><div class="profile-info-row"><div class="profile-info-name">专业方向</div><div class="profile-info-value">园艺专业果树</div><div class="profile-info-name">班级</div><div class="profile-info-value">22园艺1</div></div><div class="profile-info-row"><div class="profile-info-name">校区</div><div class="profile-info-value">主校</div><div class="profile-info-name">辅修专业</div><div class="profile-info-value"></div></div><div class="profile-info-row"><div class="profile-info-name">第二学位专业</div><div class="profile-info-value"></div><div class="profile-info-name">是否有学籍</div><div class="profile-info-value">是</div></div><div class="profile-info-row"><div class="profile-info-name">是否有国家学籍</div><div class="profile-info-value">是</div><div class="profile-info-name">学生类别</div><div class="profile-info-value"></div></div><div class="profile-info-row"><div class="profile-info-name">学籍状态</div><div class="profile-info-value">在学</div><div class="profile-info-name">学科门类</div><div class="profile-info-value"></div></div><div class="profile-info-row"><div class="profile-info-name">特殊学生类型</div><div class="profile-info-value">普通类</div><div class="profile-info-name">收费类别</div><div class="profile-info-value"></div></div><div class="profile-info-row"><div class="profile-info-name">分流方向</div><div class="profile-info-value"></div><div class="profile-info-name">培养方式</div><div class="profile-info-value"></div></div><div class="profile-info-row"><div class="profile-info-name">入学日期</div><div class="profile-info-value">20220901</div><div class="profile-info-name">因材施教</div><div class="profile-info-value"></div></div><div class="profile-info-row"><div class="profile-info-name">培养层次</div><div class="profile-info-value">本科</div><div class="profile-info-name">是否离校</div><div class="profile-info-value"></div></div><div class="profile-info-row"><div class="profile-info-name">是否应届毕业</div><div class="profile-info-value"></div><div class="profile-info-name">入学年级</div><div class="profile-info-value">2022级</div></div><div class="profile-info-row"><div class="profile-info-name">学制类型</div><div class="profile-info-value">本科</div><div class="profile-info-name">学生类型</div><div class="profile-info-value">物理类</div></div><div class="profile-info-row"><div class="profile-info-name">是否留学生</div><div class="profile-info-value"></div><div class="profile-info-name"style="background-color: white;"></div><div class="profile-info-value"></div></div></div></div><h4 class="header smaller lighter grey"><img src="/assets/images/recruit.png"style="position:relative;top:-6px;">招生信息</h4><div><div class="self profile-user-info profile-user-info-striped setLabelWidth"><div class="profile-info-row"><div class="profile-info-name">性别</div><div class="profile-info-value"style="width:34%">男</div><div class="profile-info-name">民族</div><div class="profile-info-value">汉族</div></div><div class="profile-info-row"><div class="profile-info-name">政治面貌</div><div class="profile-info-value">群众</div><div class="profile-info-name">国家/地区</div><div class="profile-info-value"></div></div><div class="profile-info-row"><div class="profile-info-name">授课语种</div><div class="profile-info-value">英语</div><div class="profile-info-name">出生日期</div><div class="profile-info-value">20031101</div></div><div class="profile-info-row"><div class="profile-info-name">籍贯</div><div class="profile-info-value"></div><div class="profile-info-name">外语语种</div><div class="profile-info-value"></div></div><div class="profile-info-row"><div class="profile-info-name">乘车区间</div><div class="profile-info-value"></div><div class="profile-info-name">考生特征</div><div class="profile-info-value"></div></div><div class="profile-info-row"><div class="profile-info-name">定向委培单位</div><div class="profile-info-value"></div><div class="profile-info-name">通讯地址</div><div class="profile-info-value"onclick="urp.showPlaintext('重庆重庆市开州区汉丰镇月潭街北六路安康8号院', this);"style="cursor: pointer; color: #4007a2;">******</div></div><div class="profile-info-row"><div class="profile-info-name">考区</div><div class="profile-info-value">重庆市</div><div class="profile-info-name">高考考生号</div><div class="profile-info-value">22500101152577</div></div><div class="profile-info-row"><div class="profile-info-name">高考总分</div><div class="profile-info-value"></div><div class="profile-info-name">毕业中学</div><div class="profile-info-value">重庆复旦中学</div></div><div class="profile-info-row"><div class="profile-info-name">入学考试语种</div><div class="profile-info-value"></div><div class="profile-info-name">录取号</div><div class="profile-info-value"></div></div><div class="profile-info-row"><div class="profile-info-name">录取年份</div><div class="profile-info-value">2022</div><div class="profile-info-name">学习形式</div><div class="profile-info-value"></div></div><div class="profile-info-row"><div class="profile-info-name">录取类别</div><div class="profile-info-value">农村应届</div><div class="profile-info-name"style="background-color: white;"></div><div class="profile-info-value"></div></div></div></div><h4 class="header smaller lighter grey"><img src="/assets/images/school.png"style="position:relative;top:-6px;">毕业信息</h4><div><div class="self profile-user-info profile-user-info-striped setLabelWidth"><div class="profile-info-row"><div class="profile-info-name">学位</div><div class="profile-info-value"style="width:34%"></div><div class="profile-info-name">学位证书编号</div><div class="profile-info-value"></div></div><div class="profile-info-row"><div class="profile-info-name">毕业类型</div><div class="profile-info-value"></div><div class="profile-info-name">毕业日期</div><div class="profile-info-value">20260629</div></div><div class="profile-info-row"><div class="profile-info-name">预计毕业日期</div><div class="profile-info-value"></div><div class="profile-info-name">毕业证书编号</div><div class="profile-info-value"></div></div><div class="profile-info-row"><div class="profile-info-name">离校日期</div><div class="profile-info-value"></div><div class="profile-info-name">授予学位时间</div><div class="profile-info-value"></div></div><div class="profile-info-row"><div class="profile-info-name">第二学位</div><div class="profile-info-value"></div><div class="profile-info-name">第二学位证书编号</div><div class="profile-info-value"></div></div><div class="profile-info-row"><div class="profile-info-name">辅修证书编号</div><div class="profile-info-value"></div><div class="profile-info-name"style="background-color: white;"></div><div class="profile-info-value"></div></div></div></div></div>
```

# 教务系统后端 API 接口文档

**文档版本**：V1.1.0

**更新日期**：2026-02-09

**适用环境**：校园内网

**基础协议**：HTTP/1.1

**基础 URL**：`http://10.2.0.47`

---

## 1. 全局技术说明

### 1.1 认证方式

本系统采用 **Cookie 会话认证**。所有接口请求头（Header）中必须包含登录成功后服务器下发的 Cookie 信息。

* **关键 Cookie 字段**：
* 
`JSESSIONID`: 会话唯一标识 。


* 
`route`: 路由节点标识 。


* 
`student.urpSoft.cn`: 学生用户标识 。




* **注意**：若 Cookie 失效或缺失，接口将返回 HTTP 302 重定向至登录页或 401 未授权。

### 1.2 请求头规范

除非接口特殊说明，所有请求应携带以下标准头：

* `User-Agent`: 建议模拟主流浏览器（如 Chrome/Edge）。
* 
`Referer`: 必须携带，通常为 `http://10.2.0.47/student/courseSelect/thisSemesterCurriculum/index`，用于通过防盗链检测 。


* 
`X-Requested-With`: `XMLHttpRequest`（标识 AJAX 请求）。



### 1.3 学年学期代码（Semester Code）生成规律

系统中涉及学期参数（如 `planCode`, `xnxq`, `zxjxjhh`）均遵循统一命名规则。**该参数在多数接口中为选填项；若不填，系统默认返回当前学期数据。**

**格式规则**：`StartYear-EndYear-Term-1`

* **StartYear**: 学年起始年份（例如 2025）。
* **EndYear**: 学年结束年份（例如 2026）。
* **Term**: 学期标识。`1` 代表秋季学期（上），`2` 代表春季学期（下）。
* **Suffix**: 固定后缀，通常为 `1`。

**推算示例**（生成最近 4 年数据）：

* **2025-2026 春**: `2025-2026-2-1`
* **2025-2026 秋**: `2025-2026-1-1`
* **2024-2025 春**: `2024-2025-2-1`
* ...以此类推。

---

## 2. 接口详细定义

### 2.1 检查选课状态与当前学期

**功能描述**：
用于检查当前学生的选课状态，并获取当前系统默认的学期中文名称。通常用于初始化应用时确认会话有效性及当前学期信息。

* 
**接口地址**：`/main/checkSelectCourseStatus` 


* 
**请求方法**：POST 



**请求参数**：无（依赖 Cookie 识别身份）

**响应数据结构**：

```json
{
  "zxjxjhm": "String, 当前学期中文名（如 '2025-2026学年春'）",
  "retString": "String, 状态码（'0' 通常表示正常/允许选课）",
  "jhrxxqztx": "String, 交互状态位（'0' 表示无异常）"
}

```



---

### 2.2 获取理论课程表

**功能描述**：
获取学生指定学期或当前学期的常规理论课程表，包含周次、节次、教室及教师信息。

* 
**接口地址**：`/student/courseSelect/thisSemesterCurriculum/ajaxStudentSchedule/callback` 


* 
**请求方法**：GET 



**请求参数**：

| 参数名 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| planCode | String | **否** | 学年学期代码（如 `2025-2026-2-1`）。留空则返回当前学期。 

 |

**响应数据结构**：

```json
{
  "xkxx": [ // 选课信息列表，核心数据源
    {
      "DynamicKey_Seq": { // 动态键名，格式为"课程号_序号"，如 "902021102_01"
        "courseName": "String, 课程名称",
        "attendClassTeacher": "String, 任课教师",
        "courseCategoryName": "String, 课程类别（如 '通识选修课'）",
        "coursePropertiesName": "String, 课程属性（如 '选修课'）",
        "examTypeName": "String, 考核方式",
        "unit": "Number, 学分",
        "timeAndPlaceList": [ // 上课时间地点列表
          {
            "classDay": "Integer, 星期几（1-7）",
            "classSessions": "Integer, 开始节次",
            "continuingSession": "Integer, 持续节次",
            "classroomName": "String, 教室",
            "teachingBuildingName": "String, 教学楼",
            "weekDescription": "String, 周次描述（如 '1-8周上'）",
            "classWeek": "String, 24位二进制周次掩码（1=有课，0=无课）"
          }
        ]
      }
    }
  ],
  "dateList": [ ... ], // 另一种视图格式，通常可忽略，使用 xkxx 即可
  "allUnits": "Number, 本学期总学分"
}

```



---

### 2.3 获取实习实践课表

**功能描述**：
获取非固定周次/节次的实践类课程安排（如毕业论文、基地实习等），此类课程通常以日期范围形式展示。

* 
**接口地址**：`/student/internship/sxbggl/findSxkb` 


* 
**请求方法**：GET 



**请求参数**：

| 参数名 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| xnxq | String | **否** | 学年学期代码（如 `2025-2026-2-1`）。留空则默认查询当前学期。 

 |
| flag | String | 否 | 标志位，通常留空。 |
| _ | Number | 否 | 时间戳，用于防止浏览器缓存。 |

**响应数据结构**（JSON 数组）：

```json
[
  {
    "kcm": "String, 课程名称（如 '毕业实习（园艺）'）",
    "kch": "String, 课程号",
    "xnxq": "String, 学年学期",
    "sxap": "String, 实习安排详情。包含HTML标签（<BR>），格式为 '日期范围【地点】教师'。"
  }
]

```



---

### 2.4 查询实验课程表

**功能描述**：
查询独立设课的实验课程安排。

* 
**接口地址**：`/student/courseSelect/thisSemesterCurriculum/index/queryExperiment` 


* 
**请求方法**：POST 



**请求参数**：

| 参数名 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| zxjxjhh | String | **否** | 执行教学计划号（即学期代码）。留空则查询当前学期。 

 |

**响应数据结构**：

```json
{
  "status": "Integer, 状态码（200表示成功）",
  "msg": "String, 消息提示",
  "data": [ ... ] // 实验课程列表数组，若无课则为空数组
}

```



---

### 2.5 获取节次时间配置

**功能描述**：
获取学校作息时间表（节次与具体时间的映射）以及学期周次配置。**注意：响应中的 value 字段是嵌套的 JSON 字符串，需要二次解析。**

* 
**接口地址**：`/ajax/student/getSectionAndTime` 


* 
**请求方法**：POST 



**请求参数**：

| 参数名 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| planNumber | String | 否 | 计划号，留空。 |
| ff | String | **是** | 固定值 `f`。 

 |
| xqh | String | 否 | 校区号，留空。 |

**响应数据结构**：

```json
{
  "status": 200,
  "msg": "OK",
  "data": {
    "firstday": "Integer, 一周起始日（通常为1）",
    "sectionTime": "String (JSON String), 节次时间详情。需二次JSON解析。",
    // 二次解析 sectionTime 后的结构示例：
    // [
    //   { "sessionName": "一", "startTime": "0800", "endTime": "0845", "id": { "session": 1 } }, ...
    // ]
    "section": "String (JSON String), 学期周次配置。需二次JSON解析。"
  }
}

```



---

## 3. 错误码与异常处理

系统主要通过 HTTP 状态码进行初步判断，业务逻辑错误通常包含在 200 响应的 JSON 体中。

| HTTP 状态码 | 含义 | 常见原因 | 处理建议 |
| --- | --- | --- | --- |
| **200** | 成功 | 请求成功处理 | 解析 JSON body 获取业务数据。 |
| **302** | 重定向 | Cookie 失效 / 未登录 | 捕获 302 跳转，引导用户重新执行模拟登录流程。 |
| **404** | 未找到 | 接口地址错误 | 检查 URL 拼写是否正确。 |
| **500** | 服务器错误 | 参数异常或后端故障 | 检查参数格式，或稍后重试。 |