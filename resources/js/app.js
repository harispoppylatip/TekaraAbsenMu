import { startLiveRefresh } from "./live-sync";
import { initNavigation } from "./navigation";
import { initAutoSubmit } from "./auto-submit";
import { initStudyFields } from "./study-fields";
import { initTheme } from "./theme";
import { initFaceCamera } from "./face-camera";
import { initFaceUpload } from "./face-upload";

const boot = () => {
    initTheme();
    initNavigation();
    initStudyFields();
    initAutoSubmit();
    startLiveRefresh();
    initFaceCamera();
    initFaceUpload();
};

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
} else {
    boot();
}
