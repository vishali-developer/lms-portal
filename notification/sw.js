self.addEventListener('install', function(event){
    self.skipWaiting();
});

self.addEventListener('push', function (event) {

    if (!(self.Notification && self.Notification.permission === 'granted')) {
        return;
    }

    var pushData = event.data.json();

    const options = {
        body: pushData.body,
        icon: pushData.icon,
        badge: pushData.badge,
        data: pushData.extraData
    };

    event.waitUntil(
        Promise.all([

            // Show notification
            self.registration.showNotification(pushData.title, options),

            // Send message to all open tabs
            clients.matchAll({
    type: "window",
    includeUncontrolled: true
}).then(function(clientList){

    clientList.forEach(function(client){

        console.log("Client URL:", client.url);

        if (client.focused) {
            client.postMessage({
                type: "PLAY_SOUND"
            });
        }


    });

})

        ])
    );

});

self.addEventListener('notificationclick', function (event) {

    event.notification.close();

    event.waitUntil(
        clients.openWindow(event.notification.data)
    );

});