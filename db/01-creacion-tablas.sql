CREATE TABLE persona (
    idPersona INTEGER PRIMARY KEY,
    ci BIGINT NOT NULL UNIQUE,
    nombre VARCHAR(100) NOT NULL,
    paterno VARCHAR(100) NOT NULL,
    materno VARCHAR(100) NOT NULL,
    fecha_nacimiento DATE NOT NULL
);

CREATE TABLE facultad (
    idFacultad INTEGER PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    ubicacion VARCHAR(100) NOT NULL
);

CREATE TABLE materia (
    idMateria INTEGER PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL
);

CREATE TABLE postulante (
    idPostulante INTEGER PRIMARY KEY,
    nacionalidad VARCHAR(50) NOT NULL,
    grado_estudios VARCHAR(50) NOT NULL,
    estado_postulacion VARCHAR(50) NOT NULL,
    CONSTRAINT fk_postulante_persona FOREIGN KEY (idPostulante) REFERENCES persona(idPersona)
);

CREATE TABLE coordinador (
    idCoordinador INTEGER PRIMARY KEY,
    unidad VARCHAR(100) NOT NULL,
    CONSTRAINT fk_coord_persona FOREIGN KEY (idCoordinador) REFERENCES persona(idPersona)
);

CREATE TABLE carrera (
    idCarrera INTEGER PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    idFacultad INTEGER,
    CONSTRAINT fk_carrera_facultad FOREIGN KEY (idFacultad) REFERENCES facultad(idFacultad)
);

CREATE TABLE docente (
    idDocente INTEGER PRIMARY KEY,
    cargo VARCHAR(100) NOT NULL,
    idMateria INTEGER,
    CONSTRAINT fk_docente_persona FOREIGN KEY (idDocente) REFERENCES persona(idPersona),
    CONSTRAINT fk_docente_materia FOREIGN KEY (idMateria) REFERENCES materia(idMateria)
);

CREATE TABLE se_dicta (
    idCarrera INTEGER,
    idMateria INTEGER,
    PRIMARY KEY (idCarrera, idMateria),
    CONSTRAINT fk_sedicta_carrera FOREIGN KEY (idCarrera) REFERENCES carrera(idCarrera),
    CONSTRAINT fk_sedicta_materia FOREIGN KEY (idMateria) REFERENCES materia(idMateria)
);

CREATE TABLE convocatoria (
    idConvocatoria INTEGER PRIMARY KEY,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    costo_nacional NUMERIC(10, 2) NOT NULL,
    costo_extranjero NUMERIC(10, 2) NOT NULL,
    idFacultad INTEGER,
    idCoordinador INTEGER,
    CONSTRAINT fk_convocatoria_fac FOREIGN KEY (idFacultad) REFERENCES facultad(idFacultad),
    CONSTRAINT fk_convocatoria_coord FOREIGN KEY (idCoordinador) REFERENCES coordinador(idCoordinador)
);

CREATE TABLE inscripcion (
    idInscripcion INTEGER PRIMARY KEY,
    fecha_inscripcion DATE NOT NULL,
    idPostulante INTEGER,
    idConvocatoria INTEGER,
    idCarrera INTEGER,
    CONSTRAINT fk_inscripcion_post FOREIGN KEY (idPostulante) REFERENCES postulante(idPostulante),
    CONSTRAINT fk_inscripcion_conv FOREIGN KEY (idConvocatoria) REFERENCES convocatoria(idConvocatoria),
    CONSTRAINT fk_inscripcion_carr FOREIGN KEY (idCarrera) REFERENCES carrera(idCarrera)
);

CREATE TABLE rinde (
    idInscripcion INTEGER,
    idMateria INTEGER,
    nota NUMERIC NOT NULL,
    PRIMARY KEY (idInscripcion, idMateria),
    CONSTRAINT fk_rinde_inscripcion FOREIGN KEY (idInscripcion) REFERENCES inscripcion(idInscripcion),
    CONSTRAINT fk_rinde_materia FOREIGN KEY (idMateria) REFERENCES materia(idMateria)
);
