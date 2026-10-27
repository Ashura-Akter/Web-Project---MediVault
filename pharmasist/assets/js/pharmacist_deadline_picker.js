/**
 * MediVault - Pharmacist module
 * File    : assets/js/pharmacist_deadline_picker.js
 * Title   : Reservations tab: pickup deadline picker
 *
 * FRONTEND (JavaScript)
 * Combines date + hour + minute + AM/PM into the hidden field 'pharmacist_pickup_deadline'.
 */

// Pickup deadline picker: clicking AM/PM immediately updates the submitted value;
// no Enter key is required.
(function(){
    // One picker box = the date, hour, minute and AM/PM controls of a single reservation row.
    document.querySelectorAll('.mv-deadline-picker').forEach(function(box){
        const hidden=box.querySelector('.mv-deadline-value');
        const date=box.querySelector('.mv-deadline-date');
        const hour=box.querySelector('.mv-deadline-hour');
        const minute=box.querySelector('.mv-deadline-minute');
        const buttons=[...box.querySelectorAll('.mv-ampm')];
        // meridian = the current AM / PM choice.
        let meridian='AM';
        function pad(n){return String(n).padStart(2,'0');}
        // sync(): combine date + 12-hour time into 'YYYY-MM-DD HH:MM' (24-hour) and store it in the hidden input
        // that is submitted as pharmacist_pickup_deadline.
        function sync(){
            if(!date.value){hidden.value='';return;}
            let h=parseInt(hour.value||'1',10)%12;
            if(meridian==='PM') h+=12;
            hidden.value=date.value+' '+pad(h)+':'+pad(parseInt(minute.value||'0',10));
        }
        // load(): when a deadline already exists, split it back into the controls (minutes are rounded down to 5).
        function load(){
            if(!hidden.value)return;
            const parts=hidden.value.split(' '), d=parts[0], tm=(parts[1]||'').split(':');
            if(d)date.value=d;
            let h=parseInt(tm[0]||'0',10), m=parseInt(tm[1]||'0',10);
            meridian=h>=12?'PM':'AM'; h=h%12||12;
            hour.value=String(h); minute.value=String(Math.floor(m/5)*5);
            buttons.forEach(b=>b.classList.toggle('active',b.dataset.value===meridian));
            sync();
        }
        // Events: AM/PM buttons and date / hour / minute changes update the hidden value immediately.
        buttons.forEach(function(btn){btn.addEventListener('click',function(){meridian=btn.dataset.value;buttons.forEach(b=>b.classList.toggle('active',b===btn));sync();});});
        [date,hour,minute].forEach(el=>el.addEventListener('change',sync));
        load();
    });
})();
