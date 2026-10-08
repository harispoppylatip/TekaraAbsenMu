/**
 * Tukar kolom akademik pada formulir anggota sesuai peran yang dipilih.
 *
 * Siswa dan admin memakai kolom kelas, guru memakai kolom mata pelajaran.
 * Server sudah merender pilihan yang benar lebih dulu, jadi skrip ini hanya
 * perlu menyesuaikan tampilan saat pilihan peran diganti pengguna.
 *
 * Formulir ditandai `data-study-form`, setiap kolomnya ditandai
 * `data-study-field` berisi peran pemilik kolom tersebut.
 */
const studyFieldKey = (role) => (role === "teacher" ? "teacher" : "student");

const applyStudyField = (form) => {
    const roleSelect = form.querySelector('[name="role"]');
    const fields = Array.from(form.querySelectorAll("[data-study-field]"));

    if (!roleSelect || fields.length === 0) {
        return;
    }

    const active = studyFieldKey(roleSelect.value);

    fields.forEach((field) => {
        field.hidden = field.dataset.studyField !== active;
    });
};

export function initStudyFields() {
    document.querySelectorAll("[data-study-form]").forEach((form) => {
        if (!form.querySelector('[name="role"]')) {
            return;
        }

        applyStudyField(form);

        form.querySelector('[name="role"]').addEventListener("change", () => {
            applyStudyField(form);
        });
    });
}
