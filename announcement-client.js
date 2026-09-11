(function () {
  'use strict'

  const API_URL = '/LessonSchedule/announcementApi.php'
  const SESSION_API_URL = '/LessonSchedule/feedbackApi.php'
  const ANNOUNCEMENT_OVERLAY_ID = 'lesson-announcement-overlay'
  const NIGHT_OVERLAY_ID = 'lesson-night-service-overlay'
  let requestPromise = null
  let scheduledTimer = null
  let moduleLoaded = false

  const readJson = (value) => {
    if (!value) return null
    if (typeof value === 'object') return value
    try { return JSON.parse(value) } catch (_) { return null }
  }

  const readCookie = (name) => {
    const prefix = `${encodeURIComponent(`LessonSchedule.${name}`)}=`
    const item = document.cookie.split(';').map((part) => part.trim()).find((part) => part.startsWith(prefix))
    return item ? decodeURIComponent(item.slice(prefix.length)) : ''
  }

  const readLocalSession = () => {
    const normalize = (raw) => {
      const parsed = readJson(raw)
      const value = parsed?.type === 'object' && parsed.data ? parsed.data : parsed
      if (!value) return null
      const userId = String(value.userId || value.user_id || '').trim()
      const authExp = Number(value.authExp || value.auth_exp || 0)
      const authSig = String(value.authSig || value.auth_sig || '').trim()
      return userId && authExp && authSig ? { userId, authExp, authSig } : null
    }
    return normalize(localStorage.getItem('AuthSession')) || normalize(readCookie('AuthSession'))
  }

  const isNightServiceWindow = () => {
    const beijingNow = new Date(Date.now() + 8 * 60 * 60 * 1000)
    const hour = beijingNow.getUTCHours()
    return hour >= 22 || hour < 6
  }

  const postJson = async (url, body) => {
    const response = await fetch(url, {
      method: 'POST',
      cache: 'no-store',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    })
    return response.json()
  }

  const validateSession = async (session) => {
    if (!session || session.authExp * 1000 <= Date.now()) return false
    try {
      const payload = await postJson(SESSION_API_URL, {
        action: 'session',
        user_id: session.userId,
        auth_exp: session.authExp,
        auth_sig: session.authSig
      })
      return payload?.data?.authenticated === true
    } catch (_) {
      return false
    }
  }

  const loadModule = (src) => {
    if (moduleLoaded || !src) return
    moduleLoaded = true
    const script = document.createElement('script')
    script.type = 'module'
    script.crossOrigin = 'anonymous'
    script.src = src
    document.head.appendChild(script)
  }

  const requestCurrent = () => {
    if (requestPromise) return requestPromise
    requestPromise = postJson(API_URL, { action: 'current' })
      .then((payload) => payload?.data?.announcement || null)
      .catch(() => null)
      .finally(() => { requestPromise = null })
    return requestPromise
  }

  const seenKey = (announcement) => {
    return `LessonSchedule.AnnouncementSeen.${announcement.id}.${announcement.push_version}`
  }

  const hasVisibleBusinessModal = () => {
    const candidates = document.querySelectorAll('.uni-modal, .uni-popup__wrapper')
    return Array.from(candidates).some((element) => {
      const style = window.getComputedStyle(element)
      return style.display !== 'none' && style.visibility !== 'hidden' && element.getClientRects().length > 0
    })
  }

  const waitUntilModalFree = (remaining = 30) => {
    if (!hasVisibleBusinessModal() || remaining <= 0) return Promise.resolve()
    return new Promise((resolve) => {
      setTimeout(() => resolve(waitUntilModalFree(remaining - 1)), 500)
    })
  }

  const appendLinkifiedText = (element, value) => {
    const text = String(value || '')
    const urlPattern = /https?:\/\/[^\s<>"']+/gi
    const trailingPunctuation = /[.,;:!?\)\]\}\u3002\uff0c\uff1b\uff1a\uff01\uff1f\u3001\uff09\u3011]$/
    let cursor = 0
    let match

    element.replaceChildren()
    while ((match = urlPattern.exec(text)) !== null) {
      let url = match[0]
      let suffix = ''
      while (url && trailingPunctuation.test(url)) {
        suffix = url.slice(-1) + suffix
        url = url.slice(0, -1)
      }
      if (!url) continue

      element.appendChild(document.createTextNode(text.slice(cursor, match.index)))
      const link = document.createElement('a')
      link.className = 'lesson-dialog-link'
      link.href = url
      link.target = '_blank'
      link.rel = 'noopener noreferrer'
      link.textContent = url
      element.appendChild(link)
      if (suffix) element.appendChild(document.createTextNode(suffix))
      cursor = match.index + match[0].length
    }
    element.appendChild(document.createTextNode(text.slice(cursor)))
  }

  const installOverlayStyle = (overlayId) => {
    const style = document.createElement('style')
    style.dataset.lessonOverlay = overlayId
    style.textContent = `
      #${overlayId}{position:fixed;inset:0;z-index:2147483647;display:flex;max-width:100vw;align-items:center;justify-content:center;padding:22px;overflow:hidden;background:rgba(15,23,42,.58);box-sizing:border-box}
      #${overlayId} .lesson-dialog-card{width:min(520px,calc(100vw - 44px));max-width:calc(100vw - 44px);min-width:0;max-height:82vh;display:flex;flex:0 1 auto;flex-direction:column;overflow:hidden;border-radius:18px;background:#fff;box-shadow:0 24px 70px rgba(15,23,42,.28);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}
      #${overlayId} .lesson-dialog-title{max-width:100%;min-width:0;padding:22px 24px 14px;text-align:center;font-size:21px;font-weight:700;color:#172033;overflow-wrap:anywhere;word-break:break-word}
      #${overlayId} .lesson-dialog-content{max-width:100%;min-width:0;padding:0 24px 22px;overflow-x:hidden;overflow-y:auto;white-space:pre-wrap;font-size:15px;line-height:1.75;color:#4b5563;overflow-wrap:anywhere;word-break:break-word}
      #${overlayId} .lesson-dialog-link{color:#1684fc;font-weight:600;text-decoration:underline;text-decoration-thickness:1px;text-underline-offset:2px;overflow-wrap:anywhere;word-break:break-all}
      #${overlayId} .lesson-dialog-signature{max-width:100%;min-width:0;padding:0 24px 22px;text-align:right;white-space:pre-wrap;font-size:14px;line-height:1.7;color:#64748b;overflow-wrap:anywhere;word-break:break-word}
      #${overlayId} .lesson-dialog-confirm{flex:0 0 auto;height:54px;border:0;border-top:1px solid #e5e7eb;background:#fff;color:#1684fc;font-size:17px;font-weight:600}
      #${overlayId} .lesson-dialog-confirm:active{background:#f8fafc}
    `
    document.head.appendChild(style)
    return style
  }

  const renderDialog = ({ id, title, content, signature = '', onConfirm }) => {
    if (document.getElementById(id)) return false
    const overlay = document.createElement('div')
    overlay.id = id
    overlay.setAttribute('role', 'dialog')
    overlay.setAttribute('aria-modal', 'true')
    overlay.innerHTML = `
      <div class="lesson-dialog-card">
        <div class="lesson-dialog-title"></div>
        <div class="lesson-dialog-content"></div>
        <div class="lesson-dialog-signature"></div>
        <button type="button" class="lesson-dialog-confirm">知道了</button>
      </div>`
    overlay.querySelector('.lesson-dialog-title').textContent = title
    appendLinkifiedText(overlay.querySelector('.lesson-dialog-content'), content)
    const signatureElement = overlay.querySelector('.lesson-dialog-signature')
    signatureElement.textContent = signature
    signatureElement.hidden = !signature
    const style = installOverlayStyle(id)
    overlay.querySelector('.lesson-dialog-confirm').addEventListener('click', () => {
      overlay.remove()
      style.remove()
      if (typeof onConfirm === 'function') onConfirm()
    })
    document.body.appendChild(overlay)
    return true
  }

  const renderAnnouncement = async (announcement) => {
    if (!announcement || document.getElementById(ANNOUNCEMENT_OVERLAY_ID)) return false
    if (localStorage.getItem(seenKey(announcement)) === '1') return false
    await waitUntilModalFree()
    if (document.getElementById(ANNOUNCEMENT_OVERLAY_ID)) return false
    if (localStorage.getItem(seenKey(announcement)) === '1') return false
    return renderDialog({
      id: ANNOUNCEMENT_OVERLAY_ID,
      title: announcement.title || '更新公告',
      content: announcement.content || '',
      signature: announcement.signature || '',
      onConfirm: () => localStorage.setItem(seenKey(announcement), '1')
    })
  }

  const showCurrent = (options) => {
    const delay = Math.max(0, Number(options?.delay || 0))
    if (scheduledTimer) clearTimeout(scheduledTimer)
    scheduledTimer = setTimeout(async () => {
      scheduledTimer = null
      const announcement = await requestCurrent()
      await renderAnnouncement(announcement)
    }, delay)
  }

  const showNightNotice = () => {
    renderDialog({
      id: NIGHT_OVERLAY_ID,
      title: '夜间认证服务暂不可用',
      content: '当前为夜间服务关闭时段（22:00–次日06:00），企业微信认证和学校实时课表服务暂不可用。为避免页面卡死，本次不会跳转认证；请在06:00后重新进入课表。',
      onConfirm: () => showCurrent({ delay: 250 })
    })
  }

  const bootstrap = async (options) => {
    const mainScript = options?.mainScript || ''
    if (!isNightServiceWindow()) {
      loadModule(mainScript)
      showCurrent({ delay: 2600 })
      return
    }

    const session = readLocalSession()
    if (await validateSession(session)) {
      loadModule(mainScript)
      showCurrent({ delay: 2600 })
      return
    }
    showNightNotice()
  }

  window.LessonScheduleAnnouncements = {
    bootstrap,
    showCurrent,
    refresh: () => {
      requestPromise = null
      showCurrent({ delay: 0 })
    },
    readLocalSession,
    isNightServiceWindow
  }
})()
