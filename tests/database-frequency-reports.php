<?php
declare(strict_types=1);

use FitControl\Domain\Reports\FrequencyReportQuery;
use FitControl\Domain\Reports\ReportPeriod;

require_once dirname(__DIR__) . '/src/Domain/Reports/ReportPeriod.php';
require_once dirname(__DIR__) . '/src/Domain/Reports/FrequencyReportQuery.php';

function frequencyPdo(): PDO {
    $password=getenv('DB_PASSWORD');
    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',getenv('DB_HOST')?:'127.0.0.1',getenv('DB_PORT')?:'3306',getenv('DB_NAME')?:'academia_alunos'),
        getenv('DB_USER')?:'root',$password===false?'':$password,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
}
function frequencyAssert(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); }
function frequencyNear(?float $actual,float $expected,string $message):void {
    frequencyAssert($actual!==null && abs($actual-$expected)<0.0000001,$message);
}
$pdo=frequencyPdo();
$query=new FrequencyReportQuery($pdo);
$suffix=bin2hex(random_bytes(8));
$students=[];$enrollments=[];
$pdo->beginTransaction();
try {
    $actor=$pdo->prepare('INSERT INTO usuarios (nome,email,senha_hash,ativo) VALUES (:n,:e,:p,1)');
    $actor->execute(['n'=>'Funcionário relatório frequência','e'=>'frequency-actor-'.$suffix.'@example.invalid','p'=>password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT)]);
    $actorId=(int)$pdo->lastInsertId();
    $student=$pdo->prepare('INSERT INTO alunos (nome,telefone,email,plano,ativo) VALUES (:n,:t,:e,:p,1)');
    for($i=0;$i<4;$i++){
        $student->execute(['n'=>'Aluno frequência '.$suffix.' '.$i,'t'=>'219'.str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT),'e'=>'frequency-'.$suffix.'-'.$i.'@example.invalid','p'=>'Teste']);
        $students[]=(int)$pdo->lastInsertId();
    }
    $enroll=$pdo->prepare("INSERT INTO matriculas (aluno_id,plano_id,plano_nome_contratado,valor_contratado,duracao_contratada_valor,duracao_contratada_unidade,inicio_em,fim_exclusivo_em,dia_referencia_calendario,cancelada_em,origem) VALUES (:id,NULL,'Plano teste',NULL,NULL,NULL,'2026-01-01 00:00:00','2027-01-01 00:00:00',NULL,NULL,'LEGADO')");
    for($i=0;$i<3;$i++){ $enroll->execute(['id'=>$students[$i]]); $enrollments[$i]=(int)$pdo->lastInsertId(); }
    $checkin=$pdo->prepare('INSERT INTO checkins (aluno_id,matricula_id,usuario_id,data_local,registrado_em_utc) VALUES (:s,:m,:u,:d,:utc)');
    foreach([[0,'2026-11-01'],[0,'2026-11-02'],[0,'2026-11-03'],[0,'2026-11-09'],[1,'2026-11-09'],[2,'2026-11-10'],[1,'2026-11-15']] as [$i,$date]){
        $checkin->execute(['s'=>$students[$i],'m'=>$enrollments[$i],'u'=>$actorId,'d'=>$date,'utc'=>$date.' 15:00:00']);
    }
    $asOf=new DateTimeImmutable('2026-11-23 12:00:00',new DateTimeZone(ReportPeriod::ACADEMY_TIMEZONE));
    $period=ReportPeriod::fromInput('2026-11-02','2026-11-10',$asOf);
    $result=$query->forPeriod($period);
    frequencyAssert($result['fr01_total_checkins']===5,'FR-01 deve contar cinco check-ins no período inclusivo.');
    frequencyAssert($result['fr02_distinct_students']===3,'FR-02 deve contar três frequentadores e excluir aluno ativo sem entrada.');
    frequencyAssert($result['fr01_total_checkins'] >= $result['fr02_distinct_students'],'FR-01 deve ser maior ou igual a FR-02.');
    $daily=[];foreach($result['fr03_daily_series'] as $row){$daily[$row['date']]=$row['checkins'];}
    frequencyAssert($daily===['2026-11-02'=>1,'2026-11-03'=>1,'2026-11-04'=>0,'2026-11-05'=>0,'2026-11-06'=>0,'2026-11-07'=>0,'2026-11-08'=>0,'2026-11-09'=>2,'2026-11-10'=>1],'FR-03 deve preencher todas as datas com zero e preservar a ordem.');
    $week=[];foreach($result['fr04_by_weekday'] as $row){$week[$row['weekday_iso']]=$row;}
    frequencyAssert(count($week)===7,'FR-04 deve retornar os sete dias ISO.');
    frequencyAssert($week[1]['calendar_occurrences']===2 && $week[1]['total_checkins']===3,'FR-04 deve contar as duas segundas-feiras.');
    frequencyNear($week[1]['average_per_occurrence'],1.5,'FR-04 média de segunda-feira incorreta.');
    frequencyAssert($week[2]['calendar_occurrences']===2 && $week[2]['total_checkins']===2,'FR-04 deve contar as duas terças-feiras.');
    frequencyNear($week[2]['average_per_occurrence'],1.0,'FR-04 média de terça-feira incorreta.');
    frequencyNear($week[3]['average_per_occurrence'],0.0,'FR-04 deve distinguir zero real de denominador ausente.');
    frequencyAssert(array_sum(array_column($result['fr04_by_weekday'],'total_checkins'))===5,'FR-04 deve reconciliar com FR-01.');
    frequencyNear($result['fr05_average_days_per_student'],5/3,'FR-05 deve dividir FR-01 por FR-02 sem arredondamento.');
    frequencyAssert($result['fr05_average_days_per_student'] <= $period->getInclusiveDayCount(),'FR-05 não pode exceder dias do período.');
    $single=$query->forPeriod(ReportPeriod::fromInput('2026-11-03','2026-11-03',$asOf));
    $singleWeek=[];foreach($single['fr04_by_weekday'] as $row){$singleWeek[$row['weekday_iso']]=$row;}
    frequencyAssert($singleWeek[2]['calendar_occurrences']===1 && $singleWeek[1]['calendar_occurrences']===0 && $singleWeek[1]['average_per_occurrence']===null,'FR-04 deve marcar weekdays sem ocorrência como não aplicáveis.');
    $empty=$query->forPeriod(ReportPeriod::fromInput('2026-11-23','2026-11-23',$asOf));
    $emptyWeek=[];foreach($empty['fr04_by_weekday'] as $row){$emptyWeek[$row['weekday_iso']]=$row;}
    frequencyAssert($empty['fr01_total_checkins']===0 && $empty['fr02_distinct_students']===0,'FR-01/02 devem retornar zero sem registros.');
    frequencyAssert($empty['fr03_daily_series']===[['date'=>'2026-11-23','checkins'=>0]],'FR-03 deve retornar a data vazia com zero.');
    frequencyAssert($empty['fr05_average_days_per_student']===null,'FR-05 deve ser não aplicável sem frequentadores.');
    frequencyAssert($emptyWeek[1]['average_per_occurrence']===0.0 && $emptyWeek[2]['average_per_occurrence']===null,'FR-04 deve diferenciar média zero e não aplicável.');
    echo "Relatório de frequência: OK (FR-01 a FR-05, datas inclusivas, série diária, weekdays ISO e período vazio).\n";
} finally { if($pdo->inTransaction()) $pdo->rollBack(); }
