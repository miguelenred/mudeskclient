// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// A steady tick for the camera (camera.js): a hidden page's timers run once a second at most, a worker's keep their pace,
// so a program there still sees the camera at its rate while this page is in another tab. Given a period in ms; 0 stops.
'use strict';

let timer = 0;
self.onmessage = (e) => {
  clearInterval(timer);
  timer = e.data > 0 ? setInterval(() => self.postMessage(0), e.data) : 0;
};
