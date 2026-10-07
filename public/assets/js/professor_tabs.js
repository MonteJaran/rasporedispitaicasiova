document.addEventListener('DOMContentLoaded', function () {
  // =========================================================
  // EXISTING LOGIC (Tabs, Profile, Password)
  // =========================================================

  const tabs = document.querySelectorAll('.tabs .tab');
  const tabContents = document.querySelectorAll('.tab-content');

  function showTab(tab) {
    tabs.forEach(t => t.classList.remove('active'));
    tabContents.forEach(c => c.classList.remove('active'));
    tab.classList.add('active');
    const targetId = tab.getAttribute('data-tab');
    const content = document.getElementById(targetId);
    if (content) content.classList.add('active');
  }

  if (tabs.length > 0) {
    if (!document.querySelector('.tab.active')) showTab(tabs[0]);
    tabs.forEach(tab => tab.addEventListener('click', () => showTab(tab)));
  }

  const toggleEditBtn = document.getElementById('toggleEditBtn');
  const profileEdit = document.getElementById('profileEdit');
  const profileView = document.getElementById('profileView');

  if (toggleEditBtn && profileEdit && profileView) {
    toggleEditBtn.addEventListener('click', () => {
      const isHidden = profileEdit.classList.contains('d-none');
      if (isHidden) {
        profileEdit.classList.remove('d-none');
        profileView.classList.add('d-none');
        toggleEditBtn.textContent = 'Zatvori edit';
      } else {
        profileEdit.classList.add('d-none');
        profileView.classList.remove('d-none');
        toggleEditBtn.textContent = 'Edit profil';
      }
    });
  }

  const modalBtn = document.getElementById('openModalBtn');
  if (modalBtn) {
    modalBtn.addEventListener('click', () => {
      const modalEl = document.getElementById('passwordModal');
      if (modalEl) new bootstrap.Modal(modalEl).show();
    });
  }

  // =========================================================
  // AVAILABILITY (kalendar)
  // =========================================================

  let availabilityCalendar = null;
  const existingAvailability = window.SERVER_EXISTING_AVAILABILITY || [];
  const professorCourses = window.SERVER_PROFESSOR_COURSES || [];
  const availabilityRooms = window.SERVER_AVAILABILITY_ROOMS || [];

  const viewModeEl = document.getElementById('availability-view-mode');
  const editModeEl = document.getElementById('availability-edit-mode');
  const btnEnableEdit = document.getElementById('btn-enable-edit');
  const btnCancelEdit = document.getElementById('cancel-edit');
  const btnSave = document.getElementById('save-availability');
  const outputList = document.getElementById('availability-output');

  const BASE_DATE = '2024-01-08T00:00:00'; // ponedjeljak
  const DAY_SHORT = ['Ned', 'Pon', 'Uto', 'Sri', 'Čet', 'Pet', 'Sub'];
  const dayShort = (d) => DAY_SHORT[d] || '';
  const formatTime = (d) => d.toTimeString().slice(0, 5);

  const jsDayToDbWeekdayMonFri = (jsDay) => {
    // JS: 0=Ned,1=Pon,... -> DB: 1=Pon..5=Pet
    if (jsDay >= 1 && jsDay <= 5) return jsDay;
    return null;
  };

  function initCalendar() {
    const calendarEl = document.getElementById('availability-calendar');
    if (!calendarEl) return;

    availabilityCalendar = new FullCalendar.Calendar(calendarEl, {
    initialDate: '2024-01-08',
    initialView: 'timeGridWeek',
    firstDay: 1,
    hiddenDays: [0, 6],
    allDaySlot: false,
    slotDuration: '00:15:00',
    slotMinTime: '08:00:00',
    slotMaxTime: '21:00:00',
    selectable: true,
    editable: true,
    height: 'auto',
    locale: 'en',

    slotLabelFormat: {
      hour: '2-digit',
      minute: '2-digit',
      hour12: false
    },

    headerToolbar: { left: '', center: '', right: '' },

    dayHeaderContent: function (arg) {
        const days = [
            'Nedjelja',
            'Ponedjeljak',
            'Utorak',
            'Srijeda',
            'Četvrtak',
            'Petak',
            'Subota'
        ];
        return { html: days[arg.date.getDay()] };
    },

      select(info) {
        const events = availabilityCalendar.getEvents();
        const overlaps = events.some(ev => info.start < ev.end && info.end > ev.start);
        if (overlaps) {
          availabilityCalendar.unselect();
          return;
        }

        availabilityCalendar.addEvent({
          start: info.start,
          end: info.end,
          title: 'Dostupan',
          extendedProps: { course_id: null, requires_computer_lab: false, preferred_room_id: null }
        });

        updateOutput();
      },

      eventClick(info) {
        info.event.remove();
        updateOutput();
      },

      eventDrop: updateOutput,
      eventResize: updateOutput
    });

    availabilityCalendar.render();
  }

  function loadEventsToCalendar() {
    if (!availabilityCalendar) return;
    availabilityCalendar.removeAllEvents();

    const baseDate = new Date(BASE_DATE);

    existingAvailability.forEach(slot => {
      const dayIndex = parseInt(slot.weekday, 10); // 1..5
      const offset = dayIndex - 1;
      if (offset < 0 || offset > 6) return;

      const startD = new Date(baseDate);
      startD.setDate(baseDate.getDate() + offset);
      const [sH, sM] = slot.start_time.split(':');
      startD.setHours(+sH, +sM, 0, 0);

      const endD = new Date(baseDate);
      endD.setDate(baseDate.getDate() + offset);
      const [eH, eM] = slot.end_time.split(':');
      endD.setHours(+eH, +eM, 0, 0);

      availabilityCalendar.addEvent({
        start: startD,
        end: endD,
        title: 'Dostupan',
        extendedProps: {
          course_id: slot.course_id ? parseInt(slot.course_id, 10) : null,
          requires_computer_lab: slot.requires_computer_lab === true || slot.requires_computer_lab === 't',
          preferred_room_id: slot.preferred_room_id ? parseInt(slot.preferred_room_id, 10) : null
        }
      });
    });

    updateOutput();
  }

  function updateOutput() {
    if (!outputList || !availabilityCalendar) return;
    outputList.innerHTML = '';

    const events = availabilityCalendar.getEvents().sort((a, b) => a.start - b.start);

    if (events.length === 0) {
      outputList.innerHTML = '<li class="text-muted">Nema označenih termina.</li>';
      return;
    }

    events.forEach(ev => {
      const li = document.createElement('li');
      li.className = 'mb-2';
      const label = document.createElement('strong');
      label.textContent = `${dayShort(ev.start.getDay())}: ${formatTime(ev.start)} – ${formatTime(ev.end)}`;
      li.appendChild(label);

      // Dodatni zahtjevi uz termin: predmet baš u ovom terminu, rač. sala, željena sala
      const props = ev.extendedProps || {};
      const wrap = document.createElement('div');
      wrap.className = 'd-flex flex-wrap gap-2 align-items-center mt-1';

      const courseSel = document.createElement('select');
      courseSel.className = 'form-select form-select-sm availability-course';
      courseSel.style.width = 'auto';
      courseSel.add(new Option('Svi predmeti', ''));
      professorCourses.forEach(c => courseSel.add(new Option(c.name, c.id)));
      courseSel.value = props.course_id ? String(props.course_id) : '';
      courseSel.addEventListener('change', () => ev.setExtendedProp('course_id', courseSel.value ? parseInt(courseSel.value, 10) : null));
      wrap.appendChild(courseSel);

      const labLabel = document.createElement('label');
      labLabel.className = 'form-check-label d-flex align-items-center gap-1';
      const labChk = document.createElement('input');
      labChk.type = 'checkbox';
      labChk.className = 'form-check-input availability-lab';
      labChk.checked = !!props.requires_computer_lab;
      labChk.addEventListener('change', () => ev.setExtendedProp('requires_computer_lab', labChk.checked));
      labLabel.appendChild(labChk);
      labLabel.appendChild(document.createTextNode('Potrebna računarska sala'));
      wrap.appendChild(labLabel);

      const roomSel = document.createElement('select');
      roomSel.className = 'form-select form-select-sm availability-room';
      roomSel.style.width = 'auto';
      roomSel.add(new Option('Bilo koja sala', ''));
      availabilityRooms.forEach(r => roomSel.add(new Option(`${r.code} (${r.capacity} mjesta${r.is_computer_lab ? ', rač.' : ''})`, r.id)));
      roomSel.value = props.preferred_room_id ? String(props.preferred_room_id) : '';
      roomSel.addEventListener('change', () => ev.setExtendedProp('preferred_room_id', roomSel.value ? parseInt(roomSel.value, 10) : null));
      wrap.appendChild(roomSel);

      li.appendChild(wrap);
      outputList.appendChild(li);
    });
  }

  if (btnEnableEdit) {
    btnEnableEdit.addEventListener('click', () => {
      viewModeEl?.classList.add('d-none');
      editModeEl?.classList.remove('d-none');

      if (!availabilityCalendar) initCalendar();
      loadEventsToCalendar();

      setTimeout(() => availabilityCalendar?.render(), 50);
    });
  }

  if (btnCancelEdit) {
    btnCancelEdit.addEventListener('click', () => {
      if (confirm('Sve ne-sačuvane izmjene će biti izgubljene. Nastavi?')) {
        editModeEl?.classList.add('d-none');
        viewModeEl?.classList.remove('d-none');
      }
    });
  }

  if (btnSave) {
    btnSave.addEventListener('click', () => {
      if (!availabilityCalendar) return;

      const events = availabilityCalendar.getEvents();
      if (events.length === 0) {
        alert('Niste unijeli nijedan termin! Molimo unesite bar jedan termin.');
        return;
      }

      const dataToSend = events
        .map(ev => {
          const weekday = jsDayToDbWeekdayMonFri(ev.start.getDay());
          if (!weekday) return null;

          return {
            day: weekday,
            from: formatTime(ev.start),
            to: formatTime(ev.end),
            course_id: ev.extendedProps.course_id || null,
            requires_computer_lab: !!ev.extendedProps.requires_computer_lab,
            preferred_room_id: ev.extendedProps.preferred_room_id || null
          };
        })
        .filter(Boolean);

      btnSave.disabled = true;
      btnSave.textContent = 'Čuvanje...';

      fetch('professor_panel.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'save_availability', data: dataToSend })
      })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            alert('Uspješno sačuvani termini!');
            window.location.reload();
          } else {
            alert('Greška pri čuvanju: ' + (res.error || 'Nepoznata greška'));
            btnSave.disabled = false;
            btnSave.textContent = 'Sačuvaj raspoloživost';
          }
        })
        .catch(err => {
          console.error(err);
          alert('Došlo je do greške prilikom komunikacije sa serverom.');
          btnSave.disabled = false;
          btnSave.textContent = 'Sačuvaj raspoloživost';
        });
    });
  }

  const tabAvail = document.getElementById('tab-avail');
  if (tabAvail) {
    tabAvail.addEventListener('shown.bs.tab', () => {
      if (availabilityCalendar && editModeEl && !editModeEl.classList.contains('d-none')) {
        availabilityCalendar.render();
      }
    });
  }
});
