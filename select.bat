ssh sync "psql -U postgres -d sync -c 'select * from file_chunks ; select * from service_history;  select * from files;'"  
