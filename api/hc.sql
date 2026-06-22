CREATE TABLE hr_branches (
    branch_id INT AUTO_INCREMENT PRIMARY KEY,
    branch_name VARCHAR(100) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE hr_holiday_calendar (
    holiday_id INT AUTO_INCREMENT PRIMARY KEY,
    holiday_name VARCHAR(255) NOT NULL,
    holiday_date DATE NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_holiday_date_name (holiday_name, holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE hr_holiday_branch_mapping (
    holiday_id INT NOT NULL,
    branch_id INT NOT NULL,

    PRIMARY KEY (holiday_id, branch_id),

    CONSTRAINT fk_holiday_branch_holiday
        FOREIGN KEY (holiday_id)
        REFERENCES hr_holiday_calendar (holiday_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_holiday_branch_branch
        FOREIGN KEY (branch_id)
        REFERENCES hr_branches (branch_id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


INSERT INTO hr_branches (branch_name) VALUES
('Gujarat'),
('Andhra Pradesh'),
('Telangana'),
('Tamil Nadu'),
('Delhi'),
('Maharashtra');


select * from hr_holiday_calendar;
select * from hr_holiday_branch_mapping;

select * from tbl_leave_policy;
select * from tbl_leave_policy


create table tbl_sample(
    
    id int auto_increment primary key,
    name varchar(100) not null,
    rno int not null,
    is_active BOOLEAN default true not null,
    created_at timestamp default current_timestamp
)