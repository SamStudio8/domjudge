<?php declare(strict_types=1);
namespace DOMJudgeBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use DOMJudgeBundle\Utils\Utils;
use JMS\Serializer\Annotation as Serializer;

/**
 * Result of a testcase run.
 * @ORM\Entity()
 * @ORM\Table(name="judging_run", options={"collate"="utf8mb4_unicode_ci", "charset"="utf8mb4"})
 */
class JudgingRun
{

    /**
     * @var int
     *
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     * @ORM\Column(type="integer", name="runid", options={"comment"="Unique ID"}, nullable=false)
     * @Serializer\SerializedName("id")
     * @Serializer\Type("string")
     */
    private $runid;

    /**
     * @var int
     * @ORM\Column(type="integer", name="judgingid", options={"comment"="Judging ID"}, nullable=false)
     * @Serializer\SerializedName("judgement_id")
     * @Serializer\Type("string")
     */
    private $judgingid;

    /**
     * @var int
     * @ORM\Column(type="integer", name="testcaseid", options={"comment"="Testcase ID"}, nullable=false)
     * @Serializer\Exclude()
     */
    private $testcaseid;

    /**
     * @var string
     * @ORM\Column(type="string", name="runresult", length=32, options={"comment"="Result of this run, NULL if not finished yet"}, nullable=true)
     * @Serializer\Exclude()
     */
    private $runresult;

    /**
     * @var double
     * @ORM\Column(type="float", name="runtime", options={"comment"="Submission running time on this testcase"}, nullable=true)
     * @Serializer\Exclude()
     */
    private $runtime = 1;

    /**
     * @var double
     * @ORM\Column(type="decimal", precision=32, scale=9, name="endtime", options={"comment"="Time run judging finished", "unsigned"=true}, nullable=false)
     * @Serializer\Exclude()
     */
    private $endtime;

    /**
     * @ORM\ManyToOne(targetEntity="Judging", inversedBy="runs")
     * @ORM\JoinColumn(name="judgingid", referencedColumnName="judgingid")
     * @Serializer\Exclude()
     */
    private $judging;

    /**
     * @ORM\ManyToOne(targetEntity="Testcase", inversedBy="judging_runs")
     * @ORM\JoinColumn(name="testcaseid", referencedColumnName="testcaseid")
     * @Serializer\Exclude()
     */
    private $testcase;

    /**
     * @var JudgingRunOutput
     * @ORM\OneToOne(targetEntity="DOMJudgeBundle\Entity\JudgingRunOutput")
     * @ORM\JoinColumn(name="runid", referencedColumnName="runid")
     * @Serializer\Exclude()
     */
    private $judging_run_output;


    /**
     * Get runid
     *
     * @return integer
     */
    public function getRunid()
    {
        return $this->runid;
    }

    /**
     * Set judgingid
     *
     * @param integer $judgingid
     *
     * @return JudgingRun
     */
    public function setJudgingid($judgingid)
    {
        $this->judgingid = $judgingid;

        return $this;
    }

    /**
     * Get judgingid
     *
     * @return integer
     */
    public function getJudgingid()
    {
        return $this->judgingid;
    }

    /**
     * Set testcaseid
     *
     * @param integer $testcaseid
     *
     * @return JudgingRun
     */
    public function setTestcaseid($testcaseid)
    {
        $this->testcaseid = $testcaseid;

        return $this;
    }

    /**
     * Get testcaseid
     *
     * @return integer
     */
    public function getTestcaseid()
    {
        return $this->testcaseid;
    }

    /**
     * Set runresult
     *
     * @param string $runresult
     *
     * @return JudgingRun
     */
    public function setRunresult($runresult)
    {
        $this->runresult = $runresult;

        return $this;
    }

    /**
     * Get runresult
     *
     * @return string
     */
    public function getRunresult()
    {
        return $this->runresult;
    }

    /**
     * Set runtime
     *
     * @param float $runtime
     *
     * @return JudgingRun
     */
    public function setRuntime($runtime)
    {
        $this->runtime = $runtime;

        return $this;
    }

    /**
     * Get runtime
     *
     * @return float
     * @Serializer\VirtualProperty()
     * @Serializer\SerializedName("run_time")
     * @Serializer\Type("float")
     */
    public function getRuntime()
    {
        return Utils::roundedFloat($this->runtime);
    }

    /**
     * Set endtime
     *
     * @param float $endtime
     *
     * @return JudgingRun
     */
    public function setEndtime($endtime)
    {
        $this->endtime = $endtime;

        return $this;
    }

    /**
     * Get endtime
     *
     * @return float
     */
    public function getEndtime()
    {
        return $this->endtime;
    }

    /**
     * Get the absolute end time for this run
     *
     * @return string
     * @Serializer\VirtualProperty()
     * @Serializer\SerializedName("time")
     * @Serializer\Type("string")
     */
    public function getAbsoluteEndTime()
    {
        return Utils::absTime($this->getEndtime());
    }

    /**
     * Get the relative end time for this run
     *
     * @return string
     * @Serializer\VirtualProperty()
     * @Serializer\SerializedName("contest_time")
     * @Serializer\Type("string")
     */
    public function getRelativeEndTime()
    {
        return Utils::relTime($this->getEndtime() - $this->getJudging()->getContest()->getStarttime());
    }

    /**
     * Set judging
     *
     * @param \DOMJudgeBundle\Entity\Judging $judging
     *
     * @return JudgingRun
     */
    public function setJudging(\DOMJudgeBundle\Entity\Judging $judging = null)
    {
        $this->judging = $judging;

        return $this;
    }

    /**
     * Get judging
     *
     * @return \DOMJudgeBundle\Entity\Judging
     */
    public function getJudging()
    {
        return $this->judging;
    }

    /**
     * Set testcase
     *
     * @param \DOMJudgeBundle\Entity\Testcase $testcase
     *
     * @return JudgingRun
     */
    public function setTestcase(\DOMJudgeBundle\Entity\Testcase $testcase = null)
    {
        $this->testcase = $testcase;

        return $this;
    }

    /**
     * Get testcase
     *
     * @return \DOMJudgeBundle\Entity\Testcase
     */
    public function getTestcase()
    {
        return $this->testcase;
    }

    /**
     * Get testcase rank
     * @return int
     * @Serializer\VirtualProperty()
     * @Serializer\SerializedName("ordinal")
     * @Serializer\Type("int")
     */
    public function getTestcaseRank()
    {
        return $this->getTestcase()->getRank();
    }

    /**
     * Set judgingRunOutput
     *
     * @param JudgingRunOutput $judgingRunOutput
     *
     * @return JudgingRun
     */
    public function setJudgingRunOutput(JudgingRunOutput $judgingRunOutput)
    {
        $this->judging_run_output = $judgingRunOutput;

        return $this;
    }

    /**
     * Get judgingRunOutput
     *
     * @return JudgingRunOutput
     */
    public function getJudgingRunOutput()
    {
        return $this->judging_run_output;
    }
}
