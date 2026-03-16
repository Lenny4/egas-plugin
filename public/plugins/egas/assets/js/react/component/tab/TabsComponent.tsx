import React from "react";
import Box from "@mui/material/Box";
import { Tab, Tabs } from "@mui/material";
import { CustomTabPanel } from "./CustomTabPanel";
import { FormTabInterface } from "../../../interface/InputInterface";
import { TOKEN } from "../../../token";

type State = {
  formTab: FormTabInterface;
};

export const TabsComponent = ({ formTab }: State) => {
  const [tabValue, setValue] = React.useState(
    Number(formTab.tabProps?.defaultValue ?? 0),
  );

  const handleChange = (event: React.SyntheticEvent, newValue: number) => {
    setValue(newValue);
  };

  React.useEffect(() => {
    const handler = (e: any) => {
      setValue(Number(e.detail));
    };
    window.addEventListener(`${TOKEN}-tabpanel-${formTab.id}`, handler);
    return () => {
      window.removeEventListener(`${TOKEN}-tabpanel-${formTab.id}`, handler);
    };
  }, [formTab.id]);

  return (
    <>
      <Box sx={{ borderBottom: 1, borderColor: "divider" }}>
        <Tabs
          {...formTab.tabProps}
          value={tabValue}
          onChange={handleChange}
          variant="scrollable"
          scrollButtons="auto"
          aria-label="scrollable auto formTab"
        >
          {formTab.tabs.map((tab, index) => (
            <Tab label={tab.label} key={index} />
          ))}
        </Tabs>
      </Box>
      {formTab.tabs.map((tab, index) => (
        <CustomTabPanel
          value={tabValue}
          index={index}
          key={index}
          id={formTab.id}
        >
          {tab.dom}
        </CustomTabPanel>
      ))}
    </>
  );
};
