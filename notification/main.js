const applicationServerKey = "";
let pushButton = document.querySelector('.js-push-btn');

let serviceWorkerRegistration = null;
let isPushSubscribed = false;

window.addEventListener('load', function () {
    if (!('serviceWorker' in navigator)) {
        return;
    }
    if (!('PushManager' in window)) {
        return;
    }
    navigator.serviceWorker.register('/notification/sw.js')
    .then(function(registration){
        serviceWorkerRegistration = registration;

        // sound 
  navigator.serviceWorker.onmessage = function(event) {

    console.log("Received:", event.data);

    if (event.data.type === "PLAY_SOUND") {

        const audio = new Audio("/notification/sounds/notification.mp3");

        audio.play()
        .then(() => {
            console.log("Sound Played");
        })
        .catch(err => {
            console.error("Sound Error:", err);
        });

    }

};
        // sound end 
        pushButton.style.display = "block";
        initializePushMessage();
    }).catch(function(error) {
        console.error('Unable to register service worker.', error);
    });

    pushButton.addEventListener('click', function () {
        pushButton.disabled = true;
        if (isPushSubscribed) {
            unsubscribeUserFromPush();
            updateBtn();
        } else {
            getNotificationPermission().then(function (status) {
                subscribeUserToPush()
                .then(function () {
                    updateBtn();
                })
                .catch(function (error) {
                    alert('Error:' + error);
                });
            }).catch(function (error) {
                if (error === "support") {
                    alert("Your browser doesn't support push messaging.");
                }
                else if (error === "denied") {
                    alert('You blocked notifications.');
                }
                else if(error === "default"){
                    updateBtn();
                    alert('You closed the permission prompt, Please try again.');
                }
                else {
                    alert('There was some problem try again later.');
                    console.log(error);
                }
            });
        }
    });
});

function initializePushMessage() {
    serviceWorkerRegistration.pushManager.getSubscription()
        .then(function (subscription) {
            isPushSubscribed = !(subscription === null);
            updateBtn();
        });
}

function unsubscribeUserFromPush() {
    pushButton.disabled = true;

    serviceWorkerRegistration.pushManager.getSubscription()
    .then(function(subscription) {
      if (subscription) {
        subscription.unsubscribe();
        return subscription;
      }
    })
    .then(function(subscription) {
      updateSubscriptionOnServer(subscription, false);
  
      isPushSubscribed = false;
      updateBtn();
    })
    .catch(function(error) {
      alert('Error unsubscribing');
    });
}

function updateBtn() {
    if (Notification.permission === 'denied') {
        pushButton.textContent = 'Push Messaging Blocked.';
        pushButton.disabled = true;
        return;
    }

    if (isPushSubscribed) {
        pushButton.textContent = '❌';
    } else {
        pushButton.textContent = '✅';
    }
    pushButton.disabled = false;
}

function getNotificationPermission() {
    return new Promise(function (resolve, reject) {
        if(!("Notification" in window)){
            reject('support');
        }
        else{
            Notification.requestPermission(function (permission) {
                (permission === 'granted')? resolve(permission): reject(permission);
            });
        }
    });
}

function subscribeUserToPush() {
    const subscribeOptions = {
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(applicationServerKey)
    };
    return new Promise(function (resolve, reject) {
        serviceWorkerRegistration.pushManager.subscribe(subscribeOptions)
        .then(function (subscription) {
            updateSubscriptionOnServer(subscription)
            .then(function (status) {
                isPushSubscribed = true;
                resolve(status);
            })
            .catch(function (error) {
                reject(error);
            })
        }).catch(function (error) {
            reject(error);
        });
    });
}

function updateSubscriptionOnServer(subscription = null, subscribe = true) {
    return new Promise(function (resolve, reject) {
        let extra = (subscribe)? '?subscribe': '?unsubscribe';
        fetch('/notification/save-subscription.php'+extra, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(subscription)
        })
        .then(function (response) {
            if (!response.ok) {
                reject('Bad status code from server')
            }
            return response.json();
        })
        .then(function (responseData) {
            if (!responseData.status || responseData.status !== 'ok') {
                reject(responseData.status);
            }
            resolve(responseData.status);
        })
        .catch(function (error){
            reject(error);
        });
    });
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding)
        .replace(/\-/g, '+')
        .replace(/_/g, '/');

    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

// Sound Notification

navigator.serviceWorker.addEventListener("message", function (event) {

    console.log("Message received:", event.data);

    if (event.data.type === "PLAY_SOUND") {

    const audio = new Audio("sounds/notification.mp3");

    audio.play()
    .then(() => {
        console.log("Sound Played");
    })
    .catch(err => {
        console.log("Sound Error:", err);
    });

}
});
