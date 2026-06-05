/**
 * 课后服务预算管理系统 - 前端交互脚本
 */

// 页面加载后初始化
document.addEventListener('DOMContentLoaded', function() {
    initToasts();
});

// Toast 通知自动消失
function initToasts() {
    document.querySelectorAll('.toast.show').forEach(function(t) {
        setTimeout(function() {
            t.style.transition = 'opacity 0.5s';
            t.style.opacity = '0';
            setTimeout(function() { t.remove(); }, 500);
        }, 2500);
    });
}

// 显示 Toast 通知
function showToast(message, type) {
    type = type || 'info';
    var toast = document.createElement('div');
    toast.className = 'toast toast-' + type + ' show';
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(function() {
        toast.style.transition = 'opacity 0.5s';
        toast.style.opacity = '0';
        setTimeout(function() { toast.remove(); }, 500);
    }, 2500);
}

// AJAX 封装
function ajax(url, method, data, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open(method, url, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var res = JSON.parse(xhr.responseText);
                callback(null, res);
            } catch(e) {
                callback('解析响应失败', null);
            }
        } else if (xhr.readyState === 4) {
            callback('请求失败: ' + xhr.status, null);
        }
    };
    
    var params = '';
    if (data) {
        var parts = [];
        for (var key in data) {
            if (data.hasOwnProperty(key)) {
                parts.push(encodeURIComponent(key) + '=' + encodeURIComponent(data[key]));
            }
        }
        params = parts.join('&');
    }
    
    xhr.send(params);
}

// 确认对话框
function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}