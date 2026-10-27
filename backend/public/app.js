(() => {
  'use strict';

  const $ = (selector) => document.querySelector(selector);
  const apiBaseInput = $('#api-base');
  const apiKeyInput = $('#api-key');
  const connectionStatus = $('#connection-status');
  const dialog = $('#record-dialog');
  const pageSize = 30;

  const resources = {
    personas: {
      label: 'Personas',
      pk: ['idPersona'],
      fields: [
        { key: 'ci', label: 'Documento de identidad', type: 'text', inputMode: 'numeric', pattern: '[0-9]+', required: true },
        { key: 'nombre', label: 'Nombre', required: true, max: 100 },
        { key: 'paterno', label: 'Apellido paterno', required: true, max: 100 },
        { key: 'materno', label: 'Apellido materno', required: true, max: 100 },
        { key: 'fecha_nacimiento', label: 'Fecha de nacimiento', type: 'date', required: true },
      ],
    },
    facultades: {
      label: 'Facultades',
      pk: ['idFacultad'],
      fields: [
        { key: 'nombre', label: 'Nombre', required: true, max: 100 },
        { key: 'ubicacion', label: 'Ubicación', required: true, max: 100 },
      ],
    },
    materias: {
      label: 'Materias',
      pk: ['idMateria'],
      fields: [{ key: 'nombre', label: 'Nombre', required: true, max: 100 }],
    },
    carreras: {
      label: 'Carreras',
      pk: ['idCarrera'],
      fields: [
        { key: 'nombre', label: 'Nombre', required: true, max: 100 },
        { key: 'idFacultad', label: 'Facultad', type: 'reference', resource: 'facultades', optional: true },
      ],
    },
    postulantes: {
      label: 'Postulantes',
      pk: ['idPostulante'],
      fields: [
        { key: 'ci', label: 'Documento de identidad', type: 'text', inputMode: 'numeric', pattern: '[0-9]+', required: true },
        { key: 'nombre', label: 'Nombre', required: true, max: 100 },
        { key: 'paterno', label: 'Apellido paterno', required: true, max: 100 },
        { key: 'materno', label: 'Apellido materno', required: true, max: 100 },
        { key: 'fecha_nacimiento', label: 'Fecha de nacimiento', type: 'date', required: true },
        { key: 'nacionalidad', label: 'Nacionalidad', required: true, max: 50 },
        { key: 'grado_estudios', label: 'Grado de estudios', required: true, max: 50 },
        { key: 'estado_postulacion', label: 'Estado de postulación', required: true, max: 50 },
      ],
    },
    coordinadores: {
      label: 'Coordinadores',
      pk: ['idCoordinador'],
      fields: [
        { key: 'ci', label: 'Documento de identidad', type: 'text', inputMode: 'numeric', pattern: '[0-9]+', required: true },
        { key: 'nombre', label: 'Nombre', required: true, max: 100 },
        { key: 'paterno', label: 'Apellido paterno', required: true, max: 100 },
        { key: 'materno', label: 'Apellido materno', required: true, max: 100 },
        { key: 'fecha_nacimiento', label: 'Fecha de nacimiento', type: 'date', required: true },
        { key: 'unidad', label: 'Unidad', required: true, max: 100 },
      ],
    },
    docentes: {
      label: 'Docentes',
      pk: ['idDocente'],
      fields: [
        { key: 'ci', label: 'Documento de identidad', type: 'number', required: true },
        { key: 'nombre', label: 'Nombre', required: true, max: 100 },
        { key: 'paterno', label: 'Apellido paterno', required: true, max: 100 },
        { key: 'materno', label: 'Apellido materno', required: true, max: 100 },
        { key: 'fecha_nacimiento', label: 'Fecha de nacimiento', type: 'date', required: true },
        { key: 'cargo', label: 'Cargo', required: true, max: 100 },
        { key: 'idMateria', label: 'Materia', type: 'reference', resource: 'materias', optional: true },
      ],
    },
    'se-dicta': {
      label: 'Materias por carrera',
      pk: ['idCarrera', 'idMateria'],
      fields: [
        { key: 'idCarrera', label: 'Carrera', type: 'reference', resource: 'carreras', required: true },
        { key: 'idMateria', label: 'Materia', type: 'reference', resource: 'materias', required: true },
      ],
      editable: false,
    },
    convocatorias: {
      label: 'Convocatorias',
      pk: ['idConvocatoria'],
      fields: [
        { key: 'fecha_inicio', label: 'Fecha de inicio', type: 'date', required: true },
        { key: 'fecha_fin', label: 'Fecha de finalización', type: 'date', required: true },
        { key: 'costo_nacional', label: 'Costo nacional', type: 'number', required: true, min: 0, step: '0.01' },
        { key: 'costo_extranjero', label: 'Costo extranjero', type: 'number', required: true, min: 0, step: '0.01' },
        { key: 'idFacultad', label: 'Facultad', type: 'reference', resource: 'facultades', optional: true },
        { key: 'idCoordinador', label: 'Coordinador', type: 'reference', resource: 'coordinadores', optional: true },
      ],
    },
    inscripciones: {
      label: 'Inscripciones',
      pk: ['idInscripcion'],
      fields: [
        { key: 'fecha_inscripcion', label: 'Fecha de inscripción', type: 'date', required: true },
        { key: 'idPostulante', label: 'Postulante', type: 'reference', resource: 'postulantes', required: true },
        { key: 'idConvocatoria', label: 'Convocatoria', type: 'reference', resource: 'convocatorias', required: true },
        { key: 'idCarrera', label: 'Carrera', type: 'reference', resource: 'carreras', required: true },
      ],
    },
    rinde: {
      label: 'Notas',
      pk: ['idInscripcion', 'idMateria'],
      fields: [
        { key: 'idInscripcion', label: 'Inscripción', type: 'reference', resource: 'inscripciones', required: true },
        { key: 'idMateria', label: 'Materia', type: 'reference', resource: 'materias', required: true },
        { key: 'nota', label: 'Nota (0 a 100)', type: 'number', required: true, min: 0, max: 100, step: '0.01' },
      ],
    },
  };

  let currentPage = 1;
  let currentTotal = 0;
  let editingRecord = null;
  let lookups = {};

  function apiUrl(path) {
    const base = apiBaseInput.value.trim().replace(/\/+$/, '');
    if (!base) throw new Error('Indica la dirección base de la API.');
    return `${base}${path}`;
  }

  async function request(path, options = {}) {
    const headers = new Headers(options.headers || {});
    const key = apiKeyInput.value.trim();
    if (key) headers.set('X-API-Key', key);
    if (options.body !== undefined) headers.set('Content-Type', 'application/json');

    let response;
    try {
      response = await fetch(apiUrl(path), { ...options, headers });
    } catch (error) {
      throw new Error(`No se pudo conectar con la API. Verifica la dirección y que el backend esté activo. ${error.message}`);
    }

    if (response.status === 204) return null;
    const text = await response.text();
    let payload = null;
    if (text) {
      try {
        payload = JSON.parse(text);
      } catch {
        throw new Error(`La API respondió con contenido no JSON (HTTP ${response.status}).`);
      }
    }
    if (!response.ok) {
      const message = payload?.error || payload?.message || `Error HTTP ${response.status}.`;
      const details = payload?.detalles ? ` ${JSON.stringify(payload.detalles)}` : '';
      throw new Error(`${message}${details}`);
    }
    return payload;
  }

  function field(record, key, fallback = '') {
    return record?.[key] ?? record?.[key.toLowerCase()] ?? fallback;
  }

  function resourceName() {
    return $('#resource-select').value;
  }

  function resourcePath(name = resourceName()) {
    return `/${encodeURIComponent(name)}`;
  }

  function primaryKeyPath(record, definition) {
    return definition.pk.map((key) => encodeURIComponent(field(record, key))).join('/');
  }

  function referenceLabel(resource, row) {
    const idKey = resources[resource].pk[0];
    const id = field(row, idKey, '?');
    let label = '';
    if (resource === 'postulantes' || resource === 'coordinadores' || resource === 'docentes') {
      label = [field(row, 'nombre'), field(row, 'paterno'), field(row, 'materno')].filter(Boolean).join(' ');
    } else if (resource === 'inscripciones') {
      label = `${field(row, 'postulante', 'Postulante')} · ${field(row, 'carrera', 'Carrera')}`;
    } else if (resource === 'convocatorias') {
      label = `Convocatoria · ${field(row, 'fecha_inicio', '')}`;
    } else {
      label = field(row, 'nombre') || field(row, 'materia') || field(row, 'facultad') || '';
    }
    return `#${id}${label ? ` · ${label}` : ''}`;
  }

  function showMessage(element, message, type = '') {
    element.replaceChildren();
    if (!message) return;
    const box = document.createElement('div');
    box.className = `output-message ${type}`.trim();
    box.textContent = message;
    element.append(box);
  }

  function displayValue(value) {
    if (value === null || value === undefined || value === '') return '—';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
  }

  function displayColumns(definition) {
    const keys = [...definition.pk, ...definition.fields.map((item) => item.key)];
    if (definition.pk[0] === 'idPostulante' || definition.pk[0] === 'idCoordinador' || definition.pk[0] === 'idDocente') {
      keys.splice(1, 0, 'ci', 'nombre', 'paterno', 'materno');
    }
    if (resourceName() === 'carreras') keys.push('facultad');
    if (resourceName() === 'convocatorias') keys.push('facultad', 'coordinador');
    if (resourceName() === 'inscripciones') keys.push('postulante', 'ci', 'carrera');
    if (resourceName() === 'rinde' || resourceName() === 'se-dicta') keys.push('materia', 'carrera');
    return [...new Set(keys)];
  }

  function renderTable(rows, definition) {
    const output = $('#records-table');
    output.replaceChildren();
    if (!rows.length) {
      showMessage(output, 'No se encontraron registros.');
      return;
    }
    const columns = displayColumns(definition);
    const wrap = document.createElement('div');
    wrap.className = 'data-table-wrap';
    const table = document.createElement('table');
    table.className = 'data-table';
    const head = table.createTHead().insertRow();
    for (const column of columns) {
      const cell = document.createElement('th');
      cell.scope = 'col';
      cell.textContent = columnLabels[column] || column;
      head.append(cell);
    }
    const actionsHeading = document.createElement('th');
    actionsHeading.scope = 'col';
    actionsHeading.textContent = 'Acciones';
    head.append(actionsHeading);

    const body = table.createTBody();
    for (const row of rows) {
      const tr = body.insertRow();
      for (const column of columns) {
        const cell = tr.insertCell();
        cell.textContent = displayValue(field(row, column));
        cell.title = cell.textContent;
      }
      const actions = tr.insertCell();
      if (definition.editable !== false) {
        const edit = document.createElement('button');
        edit.type = 'button';
        edit.className = 'button button-secondary button-small';
        edit.textContent = 'Editar';
        edit.addEventListener('click', () => openForm(row));
        actions.append(edit);
      } else {
        actions.textContent = '—';
      }
    }
    wrap.append(table);
    output.append(wrap);
  }

  const columnLabels = {
    idPersona: 'ID', idFacultad: 'ID', idMateria: 'Materia / ID', idCarrera: 'Carrera / ID',
    idPostulante: 'Postulante / ID', idCoordinador: 'Coordinador / ID', idDocente: 'Docente / ID',
    idConvocatoria: 'ID convocatoria', idInscripcion: 'Inscripción / ID',
    ci: 'Documento', nombre: 'Nombre', paterno: 'Apellido paterno', materno: 'Apellido materno',
    fecha_nacimiento: 'Nacimiento', ubicacion: 'Ubicación', facultad: 'Facultad',
    nacionalidad: 'Nacionalidad', grado_estudios: 'Grado de estudios',
    estado_postulacion: 'Estado', unidad: 'Unidad', cargo: 'Cargo', materia: 'Materia',
    carrera: 'Carrera', fecha_inicio: 'Inicio', fecha_fin: 'Fin',
    costo_nacional: 'Costo nacional', costo_extranjero: 'Costo extranjero',
    fecha_inscripcion: 'Fecha de inscripción', postulante: 'Postulante', coordinador: 'Coordinador', nota: 'Nota',
  };

  async function loadRecords() {
    const name = resourceName();
    const definition = resources[name];
    $('#records-message').replaceChildren();
    $('#records-table').replaceChildren();
    $('#page-status').textContent = 'Cargando registros…';
    $('#previous-page').disabled = true;
    $('#next-page').disabled = true;
    try {
      const params = new URLSearchParams({ limit: String(pageSize), page: String(currentPage) });
      const query = $('#search-query').value.trim();
      if (query) params.set('q', query);
      const result = await request(`${resourcePath(name)}?${params}`);
      renderTable(result.data || [], definition);
      currentTotal = Number(result.meta?.total || 0);
      const pages = Math.max(1, Number(result.meta?.paginas || 1));
      $('#page-status').textContent = `${currentTotal} registro(s) · Página ${currentPage} de ${pages}`;
      $('#previous-page').disabled = currentPage <= 1;
      $('#next-page').disabled = currentPage >= pages;
    } catch (error) {
      $('#page-status').textContent = '';
      showMessage($('#records-message'), error.message, 'error');
    }
  }

  async function loadLookups(definition) {
    const names = [...new Set(definition.fields.filter((item) => item.type === 'reference').map((item) => item.resource))];
    const loaded = await Promise.all(names.map(async (name) => {
      const result = await request(`${resourcePath(name)}?limit=200`);
      return [name, result.data || []];
    }));
    lookups = Object.fromEntries(loaded);
  }

  function createInput(fieldDefinition, value, disabled = false) {
    const control = fieldDefinition.type === 'reference' ? document.createElement('select') : document.createElement('input');
    control.name = fieldDefinition.key;
    control.id = `field-${fieldDefinition.key}`;
    control.required = Boolean(fieldDefinition.required && !fieldDefinition.optional);
    control.disabled = disabled;
    if (fieldDefinition.type === 'reference') {
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = fieldDefinition.optional ? 'Sin asignar' : 'Selecciona una opción';
      control.append(placeholder);
      for (const row of lookups[fieldDefinition.resource] || []) {
        const option = document.createElement('option');
        const id = field(row, resources[fieldDefinition.resource].pk[0]);
        option.value = id;
        option.textContent = referenceLabel(fieldDefinition.resource, row);
        control.append(option);
      }
    } else {
      control.type = fieldDefinition.type || 'text';
      if (fieldDefinition.inputMode) control.inputMode = fieldDefinition.inputMode;
      if (fieldDefinition.pattern) control.pattern = fieldDefinition.pattern;
      if (fieldDefinition.max) control.maxLength = fieldDefinition.max;
      if (fieldDefinition.min !== undefined) control.min = fieldDefinition.min;
      if (fieldDefinition.max !== undefined) control.max = fieldDefinition.max;
      if (fieldDefinition.step) control.step = fieldDefinition.step;
      if (fieldDefinition.type === 'number' && fieldDefinition.key === 'ci') {
        control.step = '1';
        control.inputMode = 'numeric';
      }
    }
    if (value !== null && value !== undefined) control.value = value;
    return control;
  }

  async function openForm(record = null) {
    const name = resourceName();
    const definition = resources[name];
    editingRecord = record;
    $('#record-fields').replaceChildren();
    $('#form-message').textContent = '';
    $('#form-eyebrow').textContent = record ? `Editar · ${definition.label}` : `Nuevo · ${definition.label}`;
    $('#form-title').textContent = record ? 'Editar registro' : 'Crear registro';
    $('#save-record').textContent = record ? 'Guardar cambios' : 'Crear registro';
    $('#save-record').disabled = true;
    dialog.showModal();
    try {
      await loadLookups(definition);
      const container = $('#record-fields');
      for (const item of definition.fields) {
        const wrapper = document.createElement('div');
        wrapper.className = 'field-control';
        const label = document.createElement('label');
        label.htmlFor = `field-${item.key}`;
        label.textContent = item.label;
        const isKey = definition.pk.includes(item.key);
        const control = createInput(item, field(record, item.key, ''), Boolean(record && isKey));
        wrapper.append(label, control);
        container.append(wrapper);
      }
      $('#save-record').disabled = false;
      $('#record-fields').querySelector('input:not(:disabled), select:not(:disabled)')?.focus();
    } catch (error) {
      $('#form-message').textContent = error.message;
      $('#form-message').className = 'form-message is-error';
    }
  }

  function formPayload(definition) {
    const body = {};
    for (const item of definition.fields) {
      const control = $(`#field-${item.key}`);
      if (!control || (editingRecord && definition.pk.includes(item.key))) continue;
      const raw = control.value.trim();
      if (raw === '') {
        body[item.key] = null;
      } else if (item.type === 'number') {
        const numeric = Number(raw);
        if (!Number.isFinite(numeric)) throw new Error(`${item.label}: ingresa un número válido.`);
        body[item.key] = numeric;
      } else if (item.type === 'reference') {
        const id = Number(raw);
        if (!Number.isSafeInteger(id)) throw new Error(`${item.label}: selecciona una opción válida.`);
        body[item.key] = id;
      } else {
        body[item.key] = raw;
      }
    }
    return body;
  }

  async function saveRecord(event) {
    event.preventDefault();
    const name = resourceName();
    const definition = resources[name];
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const submit = $('#save-record');
    const message = $('#form-message');
    submit.disabled = true;
    message.textContent = '';
    try {
      const body = formPayload(definition);
      let path = resourcePath(name);
      let method = 'POST';
      if (editingRecord) {
        path += `/${primaryKeyPath(editingRecord, definition)}`;
        method = 'PUT';
      }
      await request(path, { method, body: JSON.stringify(body) });
      dialog.close();
      showMessage($('#records-message'), editingRecord ? 'Los cambios se guardaron correctamente.' : 'El registro se creó correctamente.', 'success');
      currentPage = editingRecord ? currentPage : 1;
      await loadRecords();
    } catch (error) {
      message.textContent = error.message;
      message.className = 'form-message is-error';
    } finally {
      submit.disabled = false;
    }
  }

  function switchPanel(panelName) {
    document.querySelectorAll('.tab').forEach((tab) => tab.classList.toggle('active', tab.dataset.panel === panelName));
    document.querySelectorAll('.panel').forEach((panel) => {
      const active = panel.id === `panel-${panelName}`;
      panel.hidden = !active;
      panel.classList.toggle('active', active);
    });
  }

  async function connect() {
    $('#load-data').disabled = true;
    connectionStatus.textContent = 'Conectando con la API…';
    connectionStatus.className = 'hint';
    try {
      const result = await request('/');
      const available = result.recursos || [];
      const select = $('#resource-select');
      const selected = select.value;
      select.replaceChildren();
      for (const name of available) {
        if (!resources[name]) continue;
        const option = document.createElement('option');
        option.value = name;
        option.textContent = resources[name].label;
        select.append(option);
      }
      if (resources[selected]) select.value = selected;
      if (!select.value && select.options.length) select.selectedIndex = 0;
      connectionStatus.textContent = `${select.options.length} entidades disponibles.`;
      connectionStatus.className = 'hint is-success';
      currentPage = 1;
      await loadRecords();
    } catch (error) {
      connectionStatus.textContent = error.message;
      connectionStatus.className = 'hint is-error';
      showMessage($('#records-message'), error.message, 'error');
    } finally {
      $('#load-data').disabled = false;
    }
  }

  document.querySelectorAll('.tab').forEach((tab) => {
    tab.addEventListener('click', () => switchPanel(tab.dataset.panel));
  });

  $('#load-data').addEventListener('click', connect);
  $('#resource-select').addEventListener('change', () => {
    currentPage = 1;
    $('#search-query').value = '';
    loadRecords();
  });
  $('#search-form').addEventListener('submit', (event) => {
    event.preventDefault();
    currentPage = 1;
    loadRecords();
  });
  $('#new-record').addEventListener('click', () => openForm());
  $('#record-form').addEventListener('submit', saveRecord);
  $('#close-dialog').addEventListener('click', () => dialog.close());
  $('#cancel-form').addEventListener('click', () => dialog.close());
  $('#previous-page').addEventListener('click', () => {
    if (currentPage > 1) {
      currentPage -= 1;
      loadRecords();
    }
  });
  $('#next-page').addEventListener('click', () => {
    if (currentPage * pageSize < currentTotal) {
      currentPage += 1;
      loadRecords();
    }
  });

  const sqlModeHelp = {
    ddl: 'DDL permitido: CREATE FUNCTION, CREATE PROCEDURE o CREATE TRIGGER. Envía una sola sentencia; los puntos y coma dentro de bloques $$…$$ son válidos.',
    select: 'Solo se acepta una consulta SELECT. Para definiciones de rutinas usa el modo DDL.',
    call: 'Solo se acepta CALL nombre_procedimiento(...). El procedimiento puede cambiar los datos de la base.',
    cursor: 'Escribe una consulta SELECT. El backend abrirá un cursor PostgreSQL, ejecutará FETCH ALL y lo cerrará en la misma transacción.',
  };
  const nestedCursorFunction = `CREATE OR REPLACE FUNCTION fn_demo_cursores_anidados()
RETURNS TABLE (
  id_convocatoria INTEGER,
  id_inscripcion INTEGER,
  nombre_postulante TEXT,
  nombre_carrera TEXT
)
LANGUAGE plpgsql
AS $$
DECLARE
  c_convocatorias REFCURSOR;
  c_inscripciones REFCURSOR;
  v_convocatoria RECORD;
  v_inscripcion RECORD;
BEGIN
  OPEN c_convocatorias FOR
    SELECT c.idconvocatoria
    FROM convocatoria c
    ORDER BY c.idconvocatoria;

  LOOP
    FETCH c_convocatorias INTO v_convocatoria;
    EXIT WHEN NOT FOUND;

    OPEN c_inscripciones FOR
      SELECT i.idinscripcion,
             p.nombre || ' ' || p.paterno || ' ' || p.materno AS postulante,
             COALESCE(ca.nombre, '(sin carrera)') AS carrera
      FROM inscripcion i
      JOIN persona p ON p.idpersona = i.idpostulante
      LEFT JOIN carrera ca ON ca.idcarrera = i.idcarrera
      WHERE i.idconvocatoria = v_convocatoria.idconvocatoria
      ORDER BY i.idinscripcion;

    LOOP
      FETCH c_inscripciones INTO v_inscripcion;
      EXIT WHEN NOT FOUND;

      id_convocatoria := v_convocatoria.idconvocatoria;
      id_inscripcion := v_inscripcion.idinscripcion;
      nombre_postulante := v_inscripcion.postulante;
      nombre_carrera := v_inscripcion.carrera;
      RETURN NEXT;
    END LOOP;

    CLOSE c_inscripciones;
  END LOOP;

  CLOSE c_convocatorias;
  RETURN;
END;
$$;`;
  const nestedCursorQuery = 'SELECT * FROM fn_demo_cursores_anidados();';
  const functionExampleDdl = `CREATE OR REPLACE FUNCTION public.fn_demo_total_inscripciones(p_convocatoria INTEGER)
RETURNS BIGINT
LANGUAGE sql
STABLE
AS $$
  SELECT count(*)
  FROM public.inscripcion
  WHERE idconvocatoria = p_convocatoria;
$$;`;
  const functionExampleQuery = 'SELECT public.fn_demo_total_inscripciones(1) AS total_inscripciones;';
  const procedureExampleDdl = `CREATE OR REPLACE PROCEDURE public.sp_demo_ampliar_fecha_convocatoria(
  p_id_convocatoria INTEGER,
  p_dias INTEGER
)
LANGUAGE plpgsql
AS $$
BEGIN
  IF p_dias IS NULL OR p_dias <= 0 THEN
    RAISE EXCEPTION 'La cantidad de días debe ser mayor que cero.';
  END IF;

  UPDATE public.convocatoria
  SET fecha_fin = fecha_fin + p_dias
  WHERE idconvocatoria = p_id_convocatoria;

  IF NOT FOUND THEN
    RAISE EXCEPTION 'No existe la convocatoria %.', p_id_convocatoria;
  END IF;
END;
$$;`;
  const procedureExampleCall = 'CALL public.sp_demo_ampliar_fecha_convocatoria(1, 7);';
  const triggerFunctionExampleDdl = `CREATE OR REPLACE FUNCTION public.fn_demo_validar_nombre_persona()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
  IF NEW.nombre IS NULL OR btrim(NEW.nombre) = '' THEN
    RAISE EXCEPTION 'El nombre de la persona no puede estar vacío.';
  END IF;

  NEW.nombre := btrim(NEW.nombre);
  RETURN NEW;
END;
$$;`;
  const triggerExampleDdl = `CREATE TRIGGER trg_demo_validar_nombre_persona
BEFORE INSERT OR UPDATE OF nombre ON public.persona
FOR EACH ROW
EXECUTE FUNCTION public.fn_demo_validar_nombre_persona();`;

  $('#sql-mode').addEventListener('change', (event) => {
    $('#sql-mode-help').textContent = sqlModeHelp[event.target.value];
    const examples = {
      ddl: "CREATE OR REPLACE FUNCTION fn_ejemplo()\nRETURNS TEXT\nLANGUAGE sql\nAS $$\n  SELECT 'Hola desde PostgreSQL';\n$$;",
      select: 'SELECT * FROM fn_resultados_convocatoria(1, NULL);',
      call: 'CALL sp_guardar_nota(1, 1, 80);',
      cursor: 'SELECT * FROM fn_resultados_convocatoria(1, NULL);',
    };
    $('#sql-input').placeholder = examples[event.target.value];
  });

  $('#load-nested-cursor-ddl').addEventListener('click', () => {
    $('#sql-mode').value = 'ddl';
    $('#sql-mode').dispatchEvent(new Event('change'));
    $('#sql-input').value = nestedCursorFunction;
    $('#nested-cursor-status').textContent = 'Creación cargada. Ejecuta la sentencia; luego carga y ejecuta la consulta del ejemplo.';
  });

  $('#load-nested-cursor-query').addEventListener('click', () => {
    $('#sql-mode').value = 'select';
    $('#sql-mode').dispatchEvent(new Event('change'));
    $('#sql-input').value = nestedCursorQuery;
    $('#nested-cursor-status').textContent = 'Consulta cargada. Primero debes haber creado fn_demo_cursores_anidados().';
  });

  $('#load-function-ddl').addEventListener('click', () => {
    $('#sql-mode').value = 'ddl';
    $('#sql-mode').dispatchEvent(new Event('change'));
    $('#sql-input').value = functionExampleDdl;
    $('#function-example-status').textContent = 'Creación cargada. Ejecuta la sentencia y luego carga la consulta.';
  });

  $('#load-function-query').addEventListener('click', () => {
    $('#sql-mode').value = 'select';
    $('#sql-mode').dispatchEvent(new Event('change'));
    $('#sql-input').value = functionExampleQuery;
    $('#function-example-status').textContent = 'Consulta cargada. Primero debes haber creado fn_demo_total_inscripciones().';
  });

  $('#load-procedure-ddl').addEventListener('click', () => {
    $('#sql-mode').value = 'ddl';
    $('#sql-mode').dispatchEvent(new Event('change'));
    $('#sql-input').value = procedureExampleDdl;
    $('#procedure-example-status').textContent = 'Creación cargada. Ejecuta la sentencia y luego carga el CALL.';
  });

  $('#load-procedure-call').addEventListener('click', () => {
    $('#sql-mode').value = 'call';
    $('#sql-mode').dispatchEvent(new Event('change'));
    $('#sql-input').value = procedureExampleCall;
    $('#procedure-example-status').textContent = 'Llamada cargada: suma 7 días a la convocatoria 1. Verifica el ID antes de ejecutarla.';
  });

  $('#load-trigger-function-ddl').addEventListener('click', () => {
    $('#sql-mode').value = 'ddl';
    $('#sql-mode').dispatchEvent(new Event('change'));
    $('#sql-input').value = triggerFunctionExampleDdl;
    $('#trigger-example-status').textContent = 'Función disparadora cargada. Ejecútala antes de crear el trigger.';
  });

  $('#load-trigger-ddl').addEventListener('click', () => {
    $('#sql-mode').value = 'ddl';
    $('#sql-mode').dispatchEvent(new Event('change'));
    $('#sql-input').value = triggerExampleDdl;
    $('#trigger-example-status').textContent = 'Creación cargada. Ejecuta este CREATE TRIGGER una sola vez, después de crear la función disparadora.';
  });

  $('#sql-console-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const submit = event.currentTarget.querySelector('button[type="submit"]');
    submit.disabled = true;
    try {
      const sql = $('#sql-input').value.trim();
      const key = $('#sql-console-key').value;
      if (!sql) throw new Error('Escribe una sentencia SQL.');
      if (!key) throw new Error('Ingresa la clave configurada en SQL_CONSOLE_KEY.');
      const result = await request('/sql-console', {
        method: 'POST',
        headers: { 'X-SQL-Console-Key': key },
        body: JSON.stringify({ mode: $('#sql-mode').value, sql }),
      });
      renderSqlResult('Ejecución completada', result);
    } catch (error) {
      showMessage($('#sql-console-output'), error.message, 'error');
    } finally {
      submit.disabled = false;
    }
  });

  function renderSqlResult(title, result) {
    const output = $('#sql-console-output');
    output.replaceChildren();
    const heading = document.createElement('h4');
    heading.className = 'output-title';
    heading.textContent = `${title}${result?.meta?.total !== undefined ? ` · ${result.meta.total} registro(s)` : ''}`;
    output.append(heading);
    const rows = Array.isArray(result) ? result : Array.isArray(result?.data) ? result.data : null;
    if (rows) {
      if (rows.length === 0) {
        showMessage(output, 'La consulta no devolvió registros.');
        return;
      }
      const columns = [...new Set(rows.flatMap((row) => Object.keys(row)))];
      const wrap = document.createElement('div');
      wrap.className = 'data-table-wrap';
      const table = document.createElement('table');
      table.className = 'data-table';
      const header = table.createTHead().insertRow();
      for (const column of columns) {
        const cell = document.createElement('th');
        cell.scope = 'col';
        cell.textContent = column;
        header.append(cell);
      }
      const body = table.createTBody();
      for (const row of rows) {
        const tr = body.insertRow();
        for (const column of columns) {
          const cell = tr.insertCell();
          cell.textContent = displayValue(row[column]);
        }
      }
      wrap.append(table);
      output.append(wrap);
      return;
    }
    const pre = document.createElement('pre');
    pre.className = 'json-output';
    pre.textContent = JSON.stringify(result, null, 2);
    output.append(pre);
  }

  $('#clear-sql').addEventListener('click', () => {
    $('#sql-input').value = '';
    $('#sql-console-output').replaceChildren();
    $('#sql-input').focus();
  });

  connect();
})();
